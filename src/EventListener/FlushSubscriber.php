<?php

namespace AiObservability\EventListener;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as SdkTracerProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Exports the buffered spans once the response is sent, the command ends or a worker
 * handled a message, so long-running processes do not hold them until shutdown.
 */
final class FlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TracerProviderInterface $tracerProvider,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        $events = [KernelEvents::TERMINATE => ['flush', -1024]];

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::TERMINATE] = ['flush', -1024];
        }

        if (class_exists(WorkerMessageHandledEvent::class)) {
            $events[WorkerMessageHandledEvent::class] = ['flush', -1024];
            $events[WorkerMessageFailedEvent::class] = ['flush', -1024];
        }

        return $events;
    }

    public function flush(): void
    {
        if (!$this->tracerProvider instanceof SdkTracerProviderInterface) {
            return;
        }

        try {
            $this->tracerProvider->forceFlush();
        } catch (\Throwable $exception) {
            $this->logger?->warning('Failed to flush AI observability spans: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
