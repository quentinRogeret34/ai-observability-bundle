<?php

namespace AiObservability\Tracing;

use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\Stream\ErrorEvent;
use Symfony\AI\Platform\Result\StreamResult;

/**
 * Ends the span of a streamed model call once the stream is drained, when its token usage is known.
 *
 * @internal
 */
final class StreamSpanListener extends AbstractStreamListener
{
    /**
     * @param \Closure(ModelCall, StreamResult): void $onComplete
     * @param \Closure(ModelCall, \Throwable): void   $onError
     */
    public function __construct(
        private readonly ModelCall $call,
        private readonly MessageFormatter $formatter,
        private readonly \Closure $onComplete,
        private readonly \Closure $onError,
    ) {
    }

    public function onDelta(DeltaEvent $event): void
    {
        $this->call->firstChunkAt ??= microtime(true);

        $delta = $event->getDelta();

        if ($delta instanceof TextDelta) {
            $this->call->streamedText .= $delta->getText();
        } elseif ($delta instanceof ThinkingDelta) {
            $this->call->streamedThinking .= $delta->getThinking();
        } elseif ($delta instanceof ToolCallComplete) {
            foreach ($delta->getToolCalls() as $toolCall) {
                $this->call->streamedToolCalls[] = $this->formatter->toolCall($toolCall);
            }
        }
    }

    public function onComplete(CompleteEvent $event): void
    {
        ($this->onComplete)($this->call, $event->getResult());
    }

    public function onError(ErrorEvent $event): void
    {
        ($this->onError)($this->call, $event->getError());
    }
}
