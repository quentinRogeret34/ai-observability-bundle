<?php

namespace AiObservability\Agent;

use AiObservability\Tracing\SpanStack;
use AiObservability\User\UserIdResolverInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\AI\Agent\AgentInterface;

/**
 * Wraps an agent built at runtime (outside the container) in TracedAgent so its run gets an invoke_agent span.
 */
final readonly class AgentTracer
{
    public function __construct(
        private TracerInterface $tracer,
        private SpanStack $stack,
        private ?UserIdResolverInterface $userIdResolver = null,
    ) {
    }

    public function trace(AgentInterface $agent): AgentInterface
    {
        return $agent instanceof TracedAgent ? $agent : new TracedAgent($agent, $this->tracer, $this->stack, $this->userIdResolver);
    }
}
