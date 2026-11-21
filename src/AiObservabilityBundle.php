<?php

namespace AiObservability;

use AiObservability\DependencyInjection\AgentTracingPass;
use AiObservability\EventListener\FlushSubscriber;
use AiObservability\EventListener\PlatformTracingSubscriber;
use AiObservability\EventListener\ToolTracingSubscriber;
use AiObservability\Experiment\Experiments;
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
                ->arrayNode('experiments')
                    ->info('A/B tests of system prompts, keyed by experiment id. The variant replaces the system prompt of the agent for the whole conversation.')
                    ->useAttributeAsKey('id')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('agent')
                                ->info('Name of the agent, as returned by AgentInterface::getName().')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->arrayNode('variants')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->useAttributeAsKey('name')
                                ->arrayPrototype()
                                    ->children()
                                        ->scalarNode('system_prompt')->isRequired()->cannotBeEmpty()->end()
                                        ->integerNode('weight')->defaultValue(1)->min(0)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AgentTracingPass());
    }

    /**
     * @param array{enabled: bool, capture_content: bool, tracer_provider: ?string, experiments: array<string, array{agent: string, variants: non-empty-array<string, array{system_prompt: string, weight: int}>}>} $config
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

        $services->set('ai_observability.experiments', Experiments::class)
            ->args([$config['experiments']]);

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
    }
}
