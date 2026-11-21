<?php

namespace AiObservability\Profiler;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

/**
 * The spans of the request in the profiler. They are read once the response is sent, so the runs of a streamed answer
 * are complete.
 */
final class TracingDataCollector extends DataCollector implements LateDataCollectorInterface
{
    public const NAME = 'ai_observability';
    public const TEMPLATE = '@AiObservability/Collector/tracing.html.twig';

    /** @var list<array{SpanInterface, string}> */
    private array $spans = [];

    public function __construct(
        private readonly ?string $traceUrl = null,
    ) {
    }

    public function addSpan(SpanInterface $span, string $name): void
    {
        $this->spans[] = [$span, $name];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
    }

    public function lateCollect(): void
    {
        $spans = $depths = [];

        foreach ($this->spans as [$span, $name]) {
            $context = $span->getContext();
            $data = $span instanceof ReadableSpanInterface ? $span->toSpanData() : null;
            $parent = $data?->getParentSpanId();
            $depths[$context->getSpanId()] = $depth = null !== $parent && isset($depths[$parent]) ? $depths[$parent] + 1 : 0;

            $spans[] = [
                'name' => $name,
                'depth' => $depth,
                'trace_id' => $context->getTraceId(),
                'span_id' => $context->getSpanId(),
                'kind' => match ($data?->getKind()) {
                    SpanKind::KIND_CLIENT => 'client',
                    SpanKind::KIND_SERVER => 'server',
                    SpanKind::KIND_PRODUCER => 'producer',
                    SpanKind::KIND_CONSUMER => 'consumer',
                    SpanKind::KIND_INTERNAL => 'internal',
                    default => null,
                },
                'duration' => null !== $data && $data->hasEnded() ? ($data->getEndEpochNanos() - $data->getStartEpochNanos()) / 1e6 : null,
                'error' => StatusCode::STATUS_ERROR === $data?->getStatus()->getCode() ? $data->getStatus()->getDescription() : null,
                'attributes' => $data?->getAttributes()->toArray() ?? [],
            ];
        }

        $this->data = ['spans' => $spans, 'trace_url' => $this->traceUrl];
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function reset(): void
    {
        parent::reset();
        $this->spans = [];
    }

    /**
     * @return list<array{name: string, depth: int, trace_id: string, span_id: string, kind: ?string, duration: ?float, error: ?string, attributes: array<string, mixed>}>
     */
    public function getSpans(): array
    {
        return $this->data['spans'] ?? [];
    }

    /**
     * @return array<string, ?string> url of each trace in the backend, keyed by trace id
     */
    public function getTraces(): array
    {
        $traces = [];
        foreach ($this->getSpans() as $span) {
            $traces[$span['trace_id']] = null === $this->data['trace_url'] ? null : str_replace('{trace_id}', $span['trace_id'], $this->data['trace_url']);
        }

        return $traces;
    }

    public function getInputTokens(): int
    {
        return array_sum(array_map(static fn (array $span): int => $span['attributes']['gen_ai.usage.input_tokens'] ?? 0, $this->getSpans()));
    }

    public function getOutputTokens(): int
    {
        return array_sum(array_map(static fn (array $span): int => $span['attributes']['gen_ai.usage.output_tokens'] ?? 0, $this->getSpans()));
    }

    public function getErrorCount(): int
    {
        return \count(array_filter($this->getSpans(), static fn (array $span): bool => null !== $span['error']));
    }
}
