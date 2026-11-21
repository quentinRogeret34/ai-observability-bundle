<?php

namespace AiObservability\Agent;

use AiObservability\Tracing\SpanStack;
use AiObservability\User\UserIdResolverInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Cancellation;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\InputNormalizer;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * Wraps an agent run in a GenAI invoke_agent span, parent of its model and tool spans.
 *
 * The input is turned into a message bag first, so every run has a conversation id. SteeringInputProcessor adds the
 * experiment and prompt version to the span once the agent picked them.
 */
final class TracedAgent implements AgentInterface
{
    public function __construct(
        private readonly AgentInterface $agent,
        private readonly TracerInterface $tracer,
        private readonly SpanStack $stack,
        private readonly ?UserIdResolverInterface $userIdResolver = null,
    ) {
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        $name = $this->agent->getName();
        $input = InputNormalizer::toMessageBag($input);
        $execution = $this->agent->call($input, $options);
        $conversationId = $input->getId()->toRfc4122();
        $userId = $this->userIdResolver?->resolve();
        $tracer = $this->tracer;
        $stack = $this->stack;
        $cancellation = new Cancellation();

        // The agent only runs while its execution is consumed, so the span covers the consumption
        return new Execution(static function () use ($execution, $name, $conversationId, $userId, $tracer, $stack, $cancellation): \Generator {
            $builder = $tracer->spanBuilder('invoke_agent '.$name)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setParent($stack->parentContext())
                ->setAttribute('gen_ai.operation.name', 'invoke_agent')
                ->setAttribute('gen_ai.agent.name', $name)
                ->setAttribute('gen_ai.conversation.id', $conversationId);

            if (null !== $userId) {
                $builder->setAttribute('user.id', $userId);
            }

            $span = $builder->startSpan();
            $stack->push($span, $conversationId, $name);

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
