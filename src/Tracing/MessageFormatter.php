<?php

namespace AiObservability\Tracing;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Converts Symfony AI messages to the JSON shapes of gen_ai.system_instructions,
 * gen_ai.input.messages, gen_ai.output.messages and gen_ai.tool.definitions.
 */
final class MessageFormatter
{
    /**
     * @return list<array<string, mixed>>
     */
    public function systemInstructions(mixed $input): array
    {
        if (!$input instanceof MessageBag) {
            return [];
        }

        $parts = [];
        foreach ($input->getMessages() as $message) {
            if ($message instanceof SystemMessage) {
                $parts[] = ['type' => 'text', 'content' => (string) $message->getContent()];
            }
        }

        return $parts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inputMessages(mixed $input): array
    {
        if (\is_string($input)) {
            return [['role' => 'user', 'parts' => [['type' => 'text', 'content' => $input]]]];
        }

        if (!$input instanceof MessageBag) {
            return [];
        }

        $messages = [];
        foreach ($input->getMessages() as $message) {
            $formatted = match (true) {
                $message instanceof UserMessage => ['role' => 'user', 'parts' => $this->parts($message->getContent())],
                $message instanceof AssistantMessage => ['role' => 'assistant', 'parts' => $this->parts($message->getContent())],
                $message instanceof ToolCallMessage => ['role' => 'tool', 'parts' => [[
                    'type' => 'tool_call_response',
                    'id' => $message->getToolCall()->getId(),
                    'response' => $message->asText(),
                ]]],
                default => null,
            };

            if (null !== $formatted) {
                $messages[] = $formatted;
            }
        }

        return $messages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function outputMessages(ResultInterface $result, ?string $finishReason = null): array
    {
        $parts = match (true) {
            $result instanceof TextResult => [['type' => 'text', 'content' => $result->getContent()]],
            $result instanceof ToolCallResult => array_map($this->toolCall(...), $result->getContent()),
            $result instanceof ObjectResult => [['type' => 'text', 'content' => $result->getText() ?? json_encode($result->getContent())]],
            default => [],
        };

        return $this->assistantMessage($parts, $finishReason);
    }

    /**
     * @param list<array<string, mixed>> $parts
     *
     * @return list<array<string, mixed>>
     */
    public function assistantMessage(array $parts, ?string $finishReason = null): array
    {
        if ([] === $parts) {
            return [];
        }

        $message = ['role' => 'assistant', 'parts' => $parts];

        if (null !== $finishReason) {
            $message['finish_reason'] = $finishReason;
        }

        return [$message];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(mixed $tools): array
    {
        if (!\is_iterable($tools)) {
            return [];
        }

        $definitions = [];
        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $definitions[] = array_filter([
                    'type' => 'function',
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ], static fn (mixed $value): bool => null !== $value);
            }
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    public function toolCall(ToolCall $toolCall): array
    {
        return [
            'type' => 'tool_call',
            'id' => $toolCall->getId(),
            'name' => $toolCall->getName(),
            'arguments' => $toolCall->getArguments(),
        ];
    }

    /**
     * @param ContentInterface[] $contents
     *
     * @return list<array<string, mixed>>
     */
    private function parts(array $contents): array
    {
        $parts = [];
        foreach ($contents as $content) {
            $parts[] = match (true) {
                $content instanceof Text => ['type' => 'text', 'content' => $content->getText()],
                $content instanceof Thinking => ['type' => 'reasoning', 'content' => $content->getContent()],
                $content instanceof ToolCall => $this->toolCall($content),
                default => ['type' => strtolower((new \ReflectionClass($content))->getShortName())],
            };
        }

        return $parts;
    }
}
