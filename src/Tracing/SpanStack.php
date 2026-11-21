<?php

namespace AiObservability\Tracing;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Parents model and tool spans under the agent run or tool call in progress.
 *
 * Agent runs are generators consumed lazily, so activating an OpenTelemetry scope across
 * their yields would leave the global context out of order: the stack is kept here instead.
 */
final class SpanStack implements ResetInterface
{
    /** @var list<array{span: SpanInterface, conversationId: ?string}> */
    private array $entries = [];

    public function push(SpanInterface $span, ?string $conversationId = null): void
    {
        $this->entries[] = ['span' => $span, 'conversationId' => $conversationId ?? $this->conversationId()];
    }

    public function pop(SpanInterface $span): void
    {
        for ($i = \count($this->entries) - 1; $i >= 0; --$i) {
            if ($this->entries[$i]['span'] === $span) {
                array_splice($this->entries, $i, 1);

                return;
            }
        }
    }

    public function parentContext(): ContextInterface
    {
        $current = Context::getCurrent();

        if ([] === $this->entries) {
            return $current;
        }

        return $this->entries[array_key_last($this->entries)]['span']->storeInContext($current);
    }

    public function conversationId(): ?string
    {
        return [] === $this->entries ? null : $this->entries[array_key_last($this->entries)]['conversationId'];
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}
