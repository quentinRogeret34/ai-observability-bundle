# AI Observability Bundle

OpenTelemetry tracing for [Symfony AI](https://github.com/symfony/ai). Every model call, tool call and agent
run becomes a span that follows the [GenAI semantic conventions](https://github.com/open-telemetry/semantic-conventions-genai)
and is exported over OTLP. Any OTLP backend can receive the spans: Jaeger, Grafana Tempo, Langfuse, LangSmith, or the
[hub](https://github.com/quentinRogeret34/ai-observability-hub) made for this bundle.

On top of the traces, the bundle records the feedback of the users and the pseudonymous `user.id` retention is computed
on, and shows the spans of each request in the Symfony profiler. To impose the model, system prompt and options of the
agents or run A/B tests, add [ai-steering-bundle](https://github.com/quentinRogeret34/ai-steering-bundle).

```
invoke_agent booking                      gen_ai.agent.name, gen_ai.conversation.id
├── chat gpt-4o                           gen_ai.provider.name, gen_ai.usage.input_tokens, ...
├── execute_tool search_availability      gen_ai.tool.name, gen_ai.tool.call.id
└── chat gpt-4o
```

## Installation

```bash
composer require quentinrogeret34/ai-observability-bundle
```

Register the bundle in `config/bundles.php` if Flex did not:

```php
AiObservability\AiObservabilityBundle::class => ['all' => true],
```

## Configuration

The exporter is configured with the standard OpenTelemetry environment variables:

```dotenv
OTEL_SERVICE_NAME=booking-chatbot
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
# OTEL_EXPORTER_OTLP_HEADERS=authorization=Bearer <key>
# OTEL_TRACES_SAMPLER=parentbased_traceidratio
# OTEL_TRACES_SAMPLER_ARG=0.25
```

```yaml
# config/packages/ai_observability.yaml
ai_observability:
    enabled: true
    # Records prompts, responses and tool arguments. Off by default: they may hold personal data.
    capture_content: false
    # Maximum characters kept per captured content string when capture_content is enabled (0 for unlimited).
    max_content_length: 65536
    # Reuse the TracerProvider of an existing OpenTelemetry setup instead of building one from OTEL_* variables.
    tracer_provider: null
    # Url of a trace in the tracing backend, linked from the profiler.
    trace_url: 'http://localhost:16686/trace/{trace_id}'
```

## Profiler

With WebProfilerBundle, the "AI traces" panel lists the spans of the request: name, kind, duration, tokens, error and
attributes, nested as in the trace, with a link to each trace in the backend when `trace_url` is set. The toolbar shows
the number of spans, the tokens and the errors. The spans are read once the response is sent, so a streamed answer is
complete. Nothing is recorded when the profiler is off.

## User feedback

```php
use AiObservability\Feedback\Feedback;

public function rate(Feedback $feedback, string $conversationId, Request $request): Response
{
    // 0 is unsatisfied, 1 satisfied: a thumbs down is 0, 4 stars out of 5 is 0.75
    $feedback->record($conversationId, $request->request->getBoolean('up') ? 1.0 : 0.0, $request->request->get('comment'), label: 'wrong_answer');
}
```

The score goes out as an `evaluation user_feedback` span with the attributes of the GenAI evaluation event
(`gen_ai.evaluation.name`, `gen_ai.evaluation.score.value`, `gen_ai.evaluation.score.label`, and the comment in
`gen_ai.evaluation.explanation` when `capture_content` is on). The hub joins it to the conversation.

## Users

The `invoke_agent` span and the feedback carry `user.id`, the identifier of the logged-in user hashed with
`kernel.secret` when SecurityBundle is installed. The hub computes the retention on it. For anonymous visitors, or another
id, implement `UserIdResolverInterface` and declare it:

```yaml
ai_observability:
    user_id_resolver: App\Ai\VisitorIdResolver
```

Return a pseudonymous id, never an email or a name: the spans leave the application.

Spans are flushed when the response is sent (`kernel.terminate`), when a command ends and after each Messenger message.

## What is recorded

| Span | Source | Attributes |
|---|---|---|
| `chat {model}`, `embeddings {model}` (CLIENT) | platform `InvocationEvent` to `ResultConvertedEvent` / `ResultErrorEvent` | `gen_ai.operation.name`, `gen_ai.provider.name`, `gen_ai.request.*` (model, temperature, max_tokens, top_p, stream...), `gen_ai.response.id/model/finish_reasons`, `gen_ai.usage.input_tokens/output_tokens/cache_read.input_tokens/reasoning.output_tokens`, `gen_ai.response.time_to_first_chunk` (streams), `gen_ai.conversation.id`, `error.type` |
| `execute_tool {tool}` (INTERNAL) | toolbox `ToolCallRequested` to `ToolCallSucceeded` / `ToolCallFailed` | `gen_ai.tool.name`, `gen_ai.tool.call.id`, `gen_ai.tool.description`, `gen_ai.tool.type`, `error.type` |
| `invoke_agent {agent}` (INTERNAL) | decorator on every `ai.agent` service | `gen_ai.agent.name`, `gen_ai.conversation.id`, `user.id`, `error.type`, and `ai_observability.experiment.id/variant`, `ai_observability.prompt.version` with ai-steering-bundle |
| `evaluation {name}` (INTERNAL, root) | `Feedback::record()` | `gen_ai.evaluation.name/score.value/score.label/explanation`, `gen_ai.conversation.id`, `user.id` |

With `capture_content: true`, the opt-in attributes are added as JSON strings: `gen_ai.system_instructions`,
`gen_ai.input.messages`, `gen_ai.output.messages`, `gen_ai.tool.definitions`, `gen_ai.tool.call.arguments` and
`gen_ai.tool.call.result`.

The conversation id is the id of the `MessageBag` sent to the model or the agent.

## Limits

- The GenAI conventions are still in Development status. Attribute names may change between versions.
- A model result that is never read, or a stream the caller abandons, never ends its span, so the span is not exported.
- The response id, model and finish reason are read from OpenAI and Anthropic shaped payloads only.

## Tests

```bash
vendor/bin/phpunit
```
