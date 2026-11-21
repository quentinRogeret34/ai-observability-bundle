<?php

namespace AiObservability\Profiler;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\TracerInterface;

/**
 * Hands every span started by the bundle to the profiler, whatever the tracer provider or the exporter.
 */
final readonly class TraceableTracer implements TracerInterface
{
    public function __construct(
        private TracerInterface $tracer,
        private TracingDataCollector $collector,
    ) {
    }

    public function spanBuilder(string $spanName): SpanBuilderInterface
    {
        return new TraceableSpanBuilder($this->tracer->spanBuilder($spanName), $spanName, $this->collector);
    }

    public function isEnabled(): bool
    {
        return $this->tracer->isEnabled();
    }
}
