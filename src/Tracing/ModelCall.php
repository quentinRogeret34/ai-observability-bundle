<?php

namespace AiObservability\Tracing;

use OpenTelemetry\API\Trace\SpanInterface;

/**
 * @internal
 */
final class ModelCall
{
    public ?float $firstChunkAt = null;
    public string $streamedText = '';
    public string $streamedThinking = '';
    /** @var list<array<string, mixed>> */
    public array $streamedToolCalls = [];

    public function __construct(
        public readonly SpanInterface $span,
        public readonly float $startedAt,
    ) {
    }
}
