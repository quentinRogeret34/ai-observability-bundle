<?php

namespace AiObservability;

use AiObservability\DependencyInjection\AgentTracingPass;
use AiObservability\EventListener\FlushSubscriber;
use AiObservability\EventListener\PlatformTracingSubscriber;
use AiObservability\EventListener\ToolTracingSubscriber;
use AiObservability\Feedback\Feedback;
use AiObservability\Profiler\TracingDataCollector;
use AiObservability\Tracing\MessageFormatter;
use AiObservability\Tracing\SpanStack;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class AiObservabilityBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->canBeDisabled()
            ->children()
                ->booleanNode('capture_content')
                    ->info('Records prompts, responses and tool arguments (gen_ai.input.messages, gen_ai.output.messages...). Opt-in, as they may hold personal data.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('tracer_provider')
                    ->info('Id of an OpenTelemetry TracerProviderInterface service to reuse. By default one is built from the OTEL_* environment variables.')
                    ->defaultNull()
                ->end()
                ->scalarNode('trace_url')
                    ->info('Url of a trace in the tracing backend, linked from the profiler. "{trace_id}" is replaced, e.g. "http://localhost:16686/trace/{trace_id}".')
                    ->defaultNull()
                ->end()
                ->scalarNode('user_id_resolver')
                    ->info('Id of a UserIdResolverInterface service giving the pseudonymous user.id. By default the identifier of the logged-in user, hashed with kernel.secret.')
                    ->defaultNull()
                ->end()
            ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AgentTracingPass());
    }

    /**
     * @param array{enabled: bool, capture_content: bool, tracer_provider: ?string, trace_url: ?string, user_id_resolver: ?string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$config['enabled']) {
            return;
        }

        $services = $container->services();

        if (null !== $config['tracer_provider']) {
            $services->alias('ai_observability.tracer_provider', $config['tracer_provider']);
        } else {
            $services->set('ai_observability.tracer_provider_factory', TracerProviderFactory::class);
            $services->set('ai_observability.tracer_provider', TracerProviderInterface::class)
                ->factory([service('ai_observability.tracer_provider_factory'), 'create']);
        }

        $services->set('ai_observability.tracer', TracerInterface::class)
            ->factory([service('ai_observability.tracer_provider'), 'getTracer'])
            ->args(['symfony-ai']);

        $services->set('ai_observability.span_stack', SpanStack::class)
            ->tag('kernel.reset', ['method' => 'reset']);

        $services->set('ai_observability.message_formatter', MessageFormatter::class);

        if (null !== $config['user_id_resolver']) {
            $services->alias('ai_observability.user_id_resolver', $config['user_id_resolver']);
        }

        $services->set('ai_observability.feedback', Feedback::class)
            ->args([
                service('ai_observability.tracer'),
                $config['capture_content'],
                service('ai_observability.user_id_resolver')->nullOnInvalid(),
            ]);
        $services->alias(Feedback::class, 'ai_observability.feedback');

        $services->set('ai_observability.platform_subscriber', PlatformTracingSubscriber::class)
            ->args([
                service('ai_observability.tracer'),
                service('ai_observability.span_stack'),
                service('ai_observability.message_formatter'),
                $config['capture_content'],
            ])
            ->tag('kernel.event_subscriber')
            ->tag('kernel.reset', ['method' => 'reset']);

        if (class_exists(ToolCallRequested::class)) {
            $services->set('ai_observability.tool_subscriber', ToolTracingSubscriber::class)
                ->args([
                    service('ai_observability.tracer'),
                    service('ai_observability.span_stack'),
                    $config['capture_content'],
                ])
                ->tag('kernel.event_subscriber')
                ->tag('kernel.reset', ['method' => 'reset']);
        }

        $services->set('ai_observability.flush_subscriber', FlushSubscriber::class)
            ->args([service('ai_observability.tracer_provider')])
            ->tag('kernel.event_subscriber');

        $services->set('ai_observability.data_collector', TracingDataCollector::class)
            ->args([$config['trace_url']])
            ->tag('data_collector', ['id' => TracingDataCollector::NAME, 'template' => TracingDataCollector::TEMPLATE]);
    }
}
