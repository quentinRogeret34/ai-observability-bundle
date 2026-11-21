# AI Observability Bundle

OpenTelemetry tracing and steering for [Symfony AI](https://github.com/symfony/ai). Every model call, tool call and agent
run becomes a span that follows the [GenAI semantic conventions](https://github.com/open-telemetry/semantic-conventions-genai)
and is exported over OTLP. Any OTLP backend can receive the spans: Jaeger, Grafana Tempo, Langfuse, LangSmith, or the
[hub](https://github.com/quentinRogeret34/ai-observability-hub) made for this bundle.

On top of the traces, the bundle steers the agents: it imposes their model, system prompt and options, runs A/B tests of
prompts and models, records the feedback of the users and the pseudonymous `user.id` retention is computed on. The hub
manages all of it from its pages; without the hub, the bundle configuration does.

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

## Steering the agents

Model, system prompt and options of an agent, imposed on every run:

```yaml
ai_observability:
    agents:
        booking:                      # AgentInterface::getName()
            model: gpt-4o-mini
            system_prompt: 'You book hotel rooms.'
            options: { temperature: 0.2, max_tokens: 400 }
```

Every field is optional: a missing one leaves the value of the agent. The options are merged over the ones of the call.

### Experiments

A/B tests on an agent. Each conversation (the id of the `MessageBag`) gets one variant, picked by weight and kept from
one turn to the next. A variant may change the system prompt, the model and the options; a variant that changes nothing
keeps the settings of the agent.

```yaml
ai_observability:
    experiments:
        booking_tone:
            agent: booking
            variants:
                control: { }
                concise: { system_prompt: 'You book hotel rooms. Answer in one sentence.' }
                small: { model: gpt-4o-mini, weight: 2 }
```

The `invoke_agent` span carries `ai_observability.experiment.id`, `ai_observability.experiment.variant` and, for a prompt
version of the hub, `ai_observability.prompt.version`. The hub takes the variant named `control` as reference. One
experiment per agent at a time.

### From the hub

```yaml
ai_observability:
    hub:
        url: '%env(AI_HUB_URL)%'
        token: '%env(AI_HUB_STEERING_TOKEN)%'   # HUB_STEERING_TOKEN of the hub
        # cache_ttl: 60                         # seconds between two reads
        # timeout: 2                            # seconds, beyond which the last configuration received is used
        # http_client: http_client
        # cache: cache.app
```

The bundle reads `GET /api/steering` at most once per `cache_ttl` and never fails an agent run because of the hub: when it
is down, the last configuration received keeps applying, and the hub is asked again after 10 seconds. The settings and
experiments of the hub replace the ones of the configuration, agent by agent. An empty `url` turns the hub off.

### How it is applied

`SteeringInputProcessor` is registered as an input processor of every agent of symfony/ai-bundle, before
`SystemPromptInputProcessor`. It runs after the agent saved the input in its message store, so the imposed system prompt is
sent to the model without being stored: agents with a message store keep a clean conversation. It replaces the system
prompt of the application, tool definitions included (`include_tools`).

An agent built by hand needs it in its input processors:

```php
new Agent($platform, 'gpt-4o', inputProcessors: [$steeringInputProcessor, ...], name: 'booking');
```

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
| `invoke_agent {agent}` (INTERNAL) | decorator on every `ai.agent` service | `gen_ai.agent.name`, `gen_ai.conversation.id`, `user.id`, `ai_observability.experiment.id/variant`, `ai_observability.prompt.version`, `error.type` |
| `evaluation {name}` (INTERNAL, root) | `Feedback::record()` | `gen_ai.evaluation.name/score.value/score.label/explanation`, `gen_ai.conversation.id`, `user.id` |

With `capture_content: true`, the opt-in attributes are added as JSON strings: `gen_ai.system_instructions`,
`gen_ai.input.messages`, `gen_ai.output.messages`, `gen_ai.tool.definitions`, `gen_ai.tool.call.arguments` and
`gen_ai.tool.call.result`.

The conversation id is the id of the `MessageBag` sent to the model or the agent.

## Limits

- The GenAI conventions are still in Development status. Attribute names may change between versions.
- A model result that is never read, or a stream the caller abandons, never ends its span, so the span is not exported.
- The response id, model and finish reason are read from OpenAI and Anthropic shaped payloads only.
- The steering applies to agents through their input processors: an agent outside symfony/ai-bundle needs
  `SteeringInputProcessor` in its list, else only its traces are recorded.
- Variants are assigned per conversation, not per user: a user may see several variants across conversations. The hub
  counts a user in the variant of their first conversation for the retention.

## Tests

```bash
vendor/bin/phpunit
```
