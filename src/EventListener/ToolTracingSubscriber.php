<?php

namespace AiObservability\EventListener;

use AiObservability\Tracing\SpanStack;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Turns each tool call of a Symfony AI toolbox into a GenAI execute_tool span.
 */
final class ToolTracingSubscriber implements EventSubscriberInterface, ResetInterface
{
    /** @var list<SpanInterface> */
    private array $running = [];

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly SpanStack $stack,
        private readonly bool $captureContent = false,
        private readonly int $maxContentLength = 65536,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ToolCallRequested::class => ['onRequested', -1024],
            ToolCallSucceeded::class => ['onSucceeded', 1024],
            ToolCallFailed::class => ['onFailed', 1024],
        ];
    }

    public function onRequested(ToolCallRequested $event): void
    {
        $toolCall = $event->getToolCall();
        $definition = $event->getDefinition();

        $builder = $this->tracer->spanBuilder('execute_tool '.$toolCall->getName())
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setParent($this->stack->parentContext())
            ->setAttribute('gen_ai.operation.name', 'execute_tool')
            ->setAttribute('gen_ai.tool.name', $toolCall->getName())
            ->setAttribute('gen_ai.tool.type', 'function')
            ->setAttribute('gen_ai.tool.description', $definition->getDescription());

        if ('' !== $toolCall->getId()) {
            $builder->setAttribute('gen_ai.tool.call.id', $toolCall->getId());
        }

        if (null !== $conversationId = $this->stack->conversationId()) {
            $builder->setAttribute('gen_ai.conversation.id', $conversationId);
        }

        if ($this->captureContent) {
            $builder->setAttribute('gen_ai.tool.call.arguments', $this->json($toolCall->getArguments()));
        }

        $span = $builder->startSpan();

        if ($event->isDenied()) {
            $span->setAttribute('error.type', 'tool_call_denied');
            $span->setStatus(StatusCode::STATUS_ERROR, $event->getDenialReason() ?? 'Tool execution denied.');
            $span->end();

            return;
        }

        if ($event->hasResult()) {
            $this->setResult($span, $event->getResult()?->getResult());
            $span->end();

            return;
        }

        $this->running[] = $span;
        $this->stack->push($span);
    }

    public function onSucceeded(ToolCallSucceeded $event): void
    {
        if (null === $span = $this->finish()) {
            return;
        }

        $this->setResult($span, $event->getResult()->getResult());
        $span->end();
    }

    public function onFailed(ToolCallFailed $event): void
    {
        if (null === $span = $this->finish()) {
            return;
        }

        $exception = $event->getException();
        $span->recordException($exception);
        $span->setAttribute('error.type', $exception::class);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        $span->end();
    }

    public function reset(): void
    {
        foreach ($this->running as $span) {
            $span->setAttribute('error.type', '_OTHER');
            $span->setStatus(StatusCode::STATUS_ERROR, 'The tool call ended without a result.');
            $span->end();
        }

        $this->running = [];
    }

    private function finish(): ?SpanInterface
    {
        $span = array_pop($this->running);

        if (null !== $span) {
            $this->stack->pop($span);
        }

        return $span;
    }

    private function setResult(SpanInterface $span, mixed $result): void
    {
        if ($this->captureContent && null !== $result) {
            $span->setAttribute('gen_ai.tool.call.result', \is_string($result) ? $this->truncate($result) : $this->json($result));
        }
    }

    private function json(mixed $value): string
    {
        if ($this->maxContentLength > 0) {
            $value = $this->truncateValue($value);
        }

        $encoded = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';

        return $this->truncate($encoded);
    }

    private function truncate(string $value): string
    {
        if ($this->maxContentLength <= 0 || mb_strlen($value) <= $this->maxContentLength) {
            return $value;
        }

        return mb_substr($value, 0, $this->maxContentLength).'... [truncated]';
    }

    private function truncateValue(mixed $value): mixed
    {
        if (\is_string($value)) {
            return $this->truncate($value);
        }

        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->truncateValue($item);
            }
        }

        return $value;
    }
}
