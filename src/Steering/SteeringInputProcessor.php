<?php

namespace AiObservability\Steering;

use AiObservability\Tracing\SpanStack;
use Symfony\AI\Agent\AgentAwareInterface;
use Symfony\AI\Agent\AgentAwareTrait;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\Message;

/**
 * Applies the model, system prompt and options set for the agent, from its experiment variant when it has one.
 *
 * It runs as an input processor, after the agent saved the input in its message store: the imposed system prompt is
 * sent to the model but never stored, so a conversation does not pile it up turn after turn. Registered before
 * SystemPromptInputProcessor, which leaves a message bag that already holds a system message as is.
 */
final class SteeringInputProcessor implements InputProcessorInterface, AgentAwareInterface
{
    use AgentAwareTrait;

    public function __construct(
        private readonly SteeringProvider $provider,
        private readonly SpanStack $stack,
    ) {
    }

    public function processInput(Input $input): void
    {
        if (!isset($this->agent)) {
            return;
        }

        $name = $this->agent->getName();
        $messages = $input->getMessageBag();
        $resolution = $this->provider->config()->resolve($name, $messages->getId()->toRfc4122());

        if (null === $resolution) {
            return;
        }

        $settings = $resolution->settings;

        if (null !== $settings->model) {
            $input->setModel($settings->model);
        }

        if (null !== $settings->systemPrompt) {
            $input->setMessageBag($messages->withSystemMessage(Message::forSystem($settings->systemPrompt)));
        }

        if ([] !== $settings->options) {
            $input->setOptions(array_replace($input->getOptions(), $settings->options));
        }

        $span = $this->stack->agentSpan($name);
        if (null === $span) {
            return;
        }

        if (null !== $settings->promptVersion) {
            $span->setAttribute('ai_observability.prompt.version', $settings->promptVersion);
        }

        if (null !== $resolution->experiment) {
            $span->setAttribute('ai_observability.experiment.id', $resolution->experiment);
            $span->setAttribute('ai_observability.experiment.variant', $resolution->variant);
        }
    }
}
