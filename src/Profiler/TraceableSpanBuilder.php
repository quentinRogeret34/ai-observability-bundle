<?php

namespace AiObservability\Profiler;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;

final class TraceableSpanBuilder implements SpanBuilderInterface
{
    public function __construct(
        private readonly SpanBuilderInterface $builder,
        private readonly string $name,
        private readonly TracingDataCollector $collector,
    ) {
    }

    public function setParent(ContextInterface|false|null $context): SpanBuilderInterface
    {
        $this->builder->setParent($context);

        return $this;
    }

    /**
     * @param iterable<non-empty-string, mixed> $attributes
     */
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilderInterface
    {
        $this->builder->addLink($context, $attributes);

        return $this;
    }

    public function setAttribute(string $key, mixed $value): SpanBuilderInterface
    {
        $this->builder->setAttribute($key, $value);

        return $this;
    }

    /**
     * @param iterable<non-empty-string, mixed> $attributes
     */
    public function setAttributes(iterable $attributes): SpanBuilderInterface
    {
        $this->builder->setAttributes($attributes);

        return $this;
    }

    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        $this->builder->setStartTimestamp($timestampNanos);

        return $this;
    }

    public function setSpanKind(int $spanKind): SpanBuilderInterface
    {
        $this->builder->setSpanKind($spanKind);

        return $this;
    }

    public function startSpan(): SpanInterface
    {
        $span = $this->builder->startSpan();
        $this->collector->addSpan($span, $this->name);

        return $span;
    }
}
