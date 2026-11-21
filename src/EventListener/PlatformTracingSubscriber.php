<?php

namespace AiObservability\EventListener;

use AiObservability\Tracing\MessageFormatter;
use AiObservability\Tracing\ModelCall;
use AiObservability\Tracing\SpanStack;
use AiObservability\Tracing\StreamSpanListener;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Event\ResultConvertedEvent;
use Symfony\AI\Platform\Event\ResultErrorEvent;
use Symfony\AI\Platform\Event\ResultEvent;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Turns each model invocation of a Symfony AI platform into a GenAI inference or embeddings span.
 */
final class PlatformTracingSubscriber implements EventSubscriberInterface, ResetInterface
{
    private const REQUEST_OPTIONS = [
        'temperature' => 'gen_ai.request.temperature',
        'max_tokens' => 'gen_ai.request.max_tokens',
        'max_completion_tokens' => 'gen_ai.request.max_tokens',
        'max_output_tokens' => 'gen_ai.request.max_tokens',
        'top_p' => 'gen_ai.request.top_p',
        'top_k' => 'gen_ai.request.top_k',
        'frequency_penalty' => 'gen_ai.request.frequency_penalty',
        'presence_penalty' => 'gen_ai.request.presence_penalty',
        'seed' => 'gen_ai.request.seed',
    ];

    private const PROVIDER_NAMES = [
        'azure' => 'azure.ai.openai',
        'bedrock' => 'aws.bedrock',
        'gemini' => 'gcp.gemini',
        'vertexai' => 'gcp.vertex_ai',
    ];

    /**
     * Calls between InvocationEvent and ResultEvent, which Provider::invoke() dispatches
     * synchronously: a call left here failed before the model answered.
     *
     * @var list<ModelCall>
     */
    private array $pending = [];

    /** @var \WeakMap<ResultInterface, ModelCall> */
    private \WeakMap $converted;

    /** @var \WeakMap<\Throwable, ModelCall> */
    private \WeakMap $failed;

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly SpanStack $stack,
        private readonly MessageFormatter $formatter,
        private readonly bool $captureContent = false,
    ) {
        $this->converted = new \WeakMap();
        $this->failed = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InvocationEvent::class => ['onInvocation', -1024],
            ResultEvent::class => ['onResult', -1024],
            ResultConvertedEvent::class => ['onResultConverted', 1024],
            ResultErrorEvent::class => ['onResultError', 1024],
        ];
    }

    public function onInvocation(InvocationEvent $event): void
    {
        $model = $event->getModel();
        $input = $event->getInput();
        $options = $event->getOptions();
        $operation = $this->operationName($model, $input);

        $builder = $this->tracer->spanBuilder($operation.' '.$model->getName())
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setParent($this->stack->parentContext())
            ->setAttribute('gen_ai.operation.name', $operation)
            ->setAttribute('gen_ai.request.model', $model->getName());

        foreach (self::REQUEST_OPTIONS as $option => $attribute) {
            if (isset($options[$option]) && is_scalar($options[$option])) {
                $builder->setAttribute($attribute, $options[$option]);
            }
        }

        if (isset($options['stop'])) {
            $builder->setAttribute('gen_ai.request.stop_sequences', array_values((array) $options['stop']));
        }

        if (isset($options['stream'])) {
            $builder->setAttribute('gen_ai.request.stream', (bool) $options['stream']);
        }

        if (isset($options['response_format'])) {
            $builder->setAttribute('gen_ai.output.type', 'json');
        }

        $conversationId = $input instanceof MessageBag ? $input->getId()->toRfc4122() : $this->stack->conversationId();
        if (null !== $conversationId) {
            $builder->setAttribute('gen_ai.conversation.id', $conversationId);
        }

        if ($this->captureContent) {
            $this->setJson($builder, 'gen_ai.system_instructions', $this->formatter->systemInstructions($input));
            $this->setJson($builder, 'gen_ai.input.messages', $this->formatter->inputMessages($input));
            $this->setJson($builder, 'gen_ai.tool.definitions', $this->formatter->toolDefinitions($options['tools'] ?? null));
        }

        $this->pending[] = new ModelCall($builder->startSpan(), microtime(true));
    }

    public function onResult(ResultEvent $event): void
    {
        $call = array_pop($this->pending);

        if (null === $call) {
            return;
        }

        $deferred = $event->getDeferredResult();
        $deferred->onConvert(function (ResultInterface $result) use ($call): ResultInterface {
            $this->converted[$result] = $call;

            return $result;
        });
        $deferred->onError(function (\Throwable $error) use ($call): void {
            $this->failed[$error] = $call;
        });
    }

    public function onResultConverted(ResultConvertedEvent $event): void
    {
        $result = $event->getResult();
        $call = $this->converted[$result] ?? null;

        if (null === $call) {
            return;
        }

        unset($this->converted[$result]);
        $this->setProvider($call->span, $event->getProvider(), $event->getModel());

        if ($result instanceof StreamResult) {
            $result->addListener(new StreamSpanListener($call, $this->formatter, $this->completeStream(...), $this->fail(...)));

            return;
        }

        $this->setUsage($call->span, $result->getMetadata()->get('token_usage'));
        $finishReason = $this->setResponse($call->span, $result);

        if ($this->captureContent && !$result instanceof VectorResult) {
            $this->setJson($call->span, 'gen_ai.output.messages', $this->formatter->outputMessages($result, $finishReason));
        }

        $call->span->end();
    }

    public function onResultError(ResultErrorEvent $event): void
    {
        $error = $event->getError();
        $call = $this->failed[$error] ?? null;

        if (null === $call) {
            return;
        }

        unset($this->failed[$error]);
        $this->setProvider($call->span, $event->getProvider(), $event->getModel());
        $this->fail($call, $error);
    }

    public function reset(): void
    {
        foreach ($this->pending as $call) {
            $call->span->setAttribute('error.type', '_OTHER');
            $call->span->setStatus(StatusCode::STATUS_ERROR, 'The model call ended before returning a result.');
            $call->span->end();
        }

        $this->pending = [];
    }

    private function completeStream(ModelCall $call, StreamResult $result): void
    {
        if (null !== $call->firstChunkAt) {
            $call->span->setAttribute('gen_ai.response.time_to_first_chunk', $call->firstChunkAt - $call->startedAt);
        }

        $this->setUsage($call->span, $result->getMetadata()->get('token_usage'));

        if ($this->captureContent) {
            $parts = $call->streamedToolCalls;
            if ('' !== $call->streamedText) {
                array_unshift($parts, ['type' => 'text', 'content' => $call->streamedText]);
            }

            $this->setJson($call->span, 'gen_ai.output.messages', $this->formatter->assistantMessage($parts));
        }

        $call->span->end();
    }

    private function fail(ModelCall $call, \Throwable $error): void
    {
        $call->span->recordException($error);
        $call->span->setAttribute('error.type', $error::class);
        $call->span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
        $call->span->end();
    }

    private function operationName(Model $model, mixed $input): string
    {
        if ($input instanceof MessageBag) {
            return 'chat';
        }

        if ($model->supports(Capability::EMBEDDINGS)) {
            return 'embeddings';
        }

        return $model->supports(Capability::INPUT_MESSAGES) ? 'chat' : 'generate_content';
    }

    private function setProvider(SpanInterface $span, ?ProviderInterface $provider, Model $model): void
    {
        $name = $provider?->getName() ?? strtolower(explode('\\', $model::class)[4] ?? 'unknown');

        $span->setAttribute('gen_ai.provider.name', self::PROVIDER_NAMES[$name] ?? $name);
    }

    private function setUsage(SpanInterface $span, mixed $usage): void
    {
        if (!$usage instanceof TokenUsageInterface) {
            return;
        }

        $span->setAttribute('gen_ai.usage.input_tokens', $usage->getPromptTokens());
        $span->setAttribute('gen_ai.usage.output_tokens', $usage->getCompletionTokens());

        foreach ([
            'gen_ai.usage.reasoning.output_tokens' => $usage->getThinkingTokens(),
            'gen_ai.usage.cache_read.input_tokens' => $usage->getCacheReadTokens() ?? $usage->getCachedTokens(),
            'gen_ai.usage.cache_write.input_tokens' => $usage->getCacheCreationTokens(),
        ] as $attribute => $value) {
            if (null !== $value && 0 !== $value) {
                $span->setAttribute($attribute, $value);
            }
        }
    }

    /**
     * Reads the response id, model and finish reason from OpenAI and Anthropic shaped payloads.
     */
    private function setResponse(SpanInterface $span, ResultInterface $result): ?string
    {
        try {
            $data = $result->getRawResult()?->getData() ?? [];
        } catch (\Throwable) {
            return null;
        }

        if (\is_string($data['id'] ?? null)) {
            $span->setAttribute('gen_ai.response.id', $data['id']);
        }

        if (\is_string($data['model'] ?? null)) {
            $span->setAttribute('gen_ai.response.model', $data['model']);
        }

        $finishReasons = array_values(array_filter(
            array_merge(array_column(\is_array($data['choices'] ?? null) ? $data['choices'] : [], 'finish_reason'), [$data['stop_reason'] ?? null]),
            \is_string(...),
        ));

        if ([] === $finishReasons && $result instanceof ToolCallResult) {
            $finishReasons = ['tool_calls'];
        }

        if ([] !== $finishReasons) {
            $span->setAttribute('gen_ai.response.finish_reasons', $finishReasons);
        }

        return $finishReasons[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $value
     */
    private function setJson(SpanInterface|SpanBuilderInterface $target, string $attribute, array $value): void
    {
        if ([] !== $value) {
            $target->setAttribute($attribute, json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
