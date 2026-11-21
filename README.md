# AI Observability Bundle

OpenTelemetry tracing for [Symfony AI](https://github.com/symfony/ai). Every model call, tool call and agent run
becomes a span that follows the [GenAI semantic conventions](https://github.com/open-telemetry/semantic-conventions-genai)
and is exported over OTLP. Any OTLP backend can receive the spans: Jaeger, Grafana Tempo, Langfuse, LangSmith, or the hub
that ships with this project.

```
invoke_agent booking                      gen_ai.agent.name, gen_ai.conversation.id
├── chat gpt-4o                           gen_ai.provider.name, gen_ai.usage.input_tokens, ...
├── execute_tool search_availability      gen_ai.tool.name, gen_ai.tool.call.id
└── chat gpt-4o
```

## Installation

```bash
composer require quentinrogeret34/ai-observability-bundle symfony/http-client nyholm/psr7
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
    # Reuse the TracerProvider of an existing OpenTelemetry setup instead of building one from OTEL_* variables.
    tracer_provider: null
```

## Prompt experiments

A/B test system prompts on an agent. Each conversation (the id of the `MessageBag`) gets one variant, picked by weight
and kept from one turn to the next. The variant's prompt replaces the system message of the input, and the
`invoke_agent` span carries `ai_observability.experiment.id` and `ai_observability.experiment.variant`, which the hub
compares on its Experiments page.

```yaml
ai_observability:
    experiments:
        booking_prompt:
            agent: booking            # AgentInterface::getName()
            variants:
                control: { system_prompt: 'You book hotel rooms.' }
                concise: { system_prompt: 'You book hotel rooms. Answer in one sentence.', weight: 1 }
```

The hub takes the variant named `control` as reference. One experiment per agent at a time.

Spans are flushed when the response is sent (`kernel.terminate`), when a command ends and after each Messenger message.

## What is recorded

| Span | Source | Attributes |
|---|---|---|
| `chat {model}`, `embeddings {model}` (CLIENT) | platform `InvocationEvent` to `ResultConvertedEvent` / `ResultErrorEvent` | `gen_ai.operation.name`, `gen_ai.provider.name`, `gen_ai.request.*` (model, temperature, max_tokens, top_p, stream...), `gen_ai.response.id/model/finish_reasons`, `gen_ai.usage.input_tokens/output_tokens/cache_read.input_tokens/reasoning.output_tokens`, `gen_ai.response.time_to_first_chunk` (streams), `gen_ai.conversation.id`, `error.type` |
| `execute_tool {tool}` (INTERNAL) | toolbox `ToolCallRequested` to `ToolCallSucceeded` / `ToolCallFailed` | `gen_ai.tool.name`, `gen_ai.tool.call.id`, `gen_ai.tool.description`, `gen_ai.tool.type`, `error.type` |
| `invoke_agent {agent}` (INTERNAL) | decorator on every `ai.agent` service | `gen_ai.agent.name`, `gen_ai.conversation.id`, `error.type` |

With `capture_content: true`, the opt-in attributes are added as JSON strings: `gen_ai.system_instructions`,
`gen_ai.input.messages`, `gen_ai.output.messages`, `gen_ai.tool.definitions`, `gen_ai.tool.call.arguments` and
`gen_ai.tool.call.result`.

The conversation id is the id of the `MessageBag` sent to the model or the agent.

## Limits

- The GenAI conventions are still in Development status. Attribute names may change between versions.
- A model result that is never read, or a stream the caller abandons, never ends its span, so the span is not exported.
- The response id, model and finish reason are read from OpenAI and Anthropic shaped payloads only.
- Experiments do not suit agents with a message store: the variant's system message is added to the input at every turn,
  so the stored conversation would hold it several times.

## Tests

```bash
vendor/bin/phpunit
```
