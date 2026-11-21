<?php

namespace AiObservability\Agent;

use AiObservability\Experiment\Experiments;
use AiObservability\Tracing\SpanStack;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Cancellation;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * Wraps an agent run in a GenAI invoke_agent span, parent of its model and tool spans.
 *
 * When an experiment targets the agent, the system prompt of its variant replaces the one of the input:
 * SystemPromptInputProcessor leaves a message bag that already holds a system message as is.
 */
final class TracedAgent implements AgentInterface
{
    public function __construct(
        private readonly AgentInterface $agent,
        private readonly TracerInterface $tracer,
        private readonly SpanStack $stack,
        private readonly ?Experiments $experiments = null,
    ) {
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        $name = $this->agent->getName();
        $experiment = $this->experiments?->forAgent($name);
        $variant = null;

        if (null !== $experiment) {
            $input = match (true) {
                $input instanceof MessageBag => $input,
                $input instanceof UserMessage => new MessageBag($input),
                default => new MessageBag(Message::ofUser($input)),
            };
            $variant = $experiment->assign($input->getId()->toRfc4122());
            $input = $input->withSystemMessage(Message::forSystem($experiment->systemPrompt($variant)));
        }

        $execution = $this->agent->call($input, $options);
        $conversationId = $input instanceof MessageBag ? $input->getId()->toRfc4122() : null;
        $tracer = $this->tracer;
        $stack = $this->stack;
        $cancellation = new Cancellation();

        // The agent only runs while its execution is consumed, so the span covers the consumption
        return new Execution(static function () use ($execution, $name, $conversationId, $experiment, $variant, $tracer, $stack, $cancellation): \Generator {
            $builder = $tracer->spanBuilder('invoke_agent '.$name)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setParent($stack->parentContext())
                ->setAttribute('gen_ai.operation.name', 'invoke_agent')
                ->setAttribute('gen_ai.agent.name', $name);

            if (null !== $conversationId) {
                $builder->setAttribute('gen_ai.conversation.id', $conversationId);
            }

            if (null !== $experiment) {
                $builder->setAttribute('ai_observability.experiment.id', $experiment->id);
                $builder->setAttribute('ai_observability.experiment.variant', $variant);
            }

            $span = $builder->startSpan();
            $stack->push($span, $conversationId);

            try {
                yield from $cancellation->forward($execution);

                if (null !== $error = $execution->getError()) {
                    $span->setAttribute('error.type', '_OTHER');
                    $span->setStatus(StatusCode::STATUS_ERROR, $error);
                }
            } catch (\Throwable $exception) {
                $span->recordException($exception);
                $span->setAttribute('error.type', $exception::class);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

                throw $exception;
            } finally {
                $stack->pop($span);
                $span->end();
            }
        }, $execution->isStreamed(), $cancellation);
    }

    public function getName(): string
    {
        return $this->agent->getName();
    }
}
