<?php

namespace AiObservability;

use AiObservability\DependencyInjection\AgentTracingPass;
use AiObservability\EventListener\FlushSubscriber;
use AiObservability\EventListener\PlatformTracingSubscriber;
use AiObservability\EventListener\ToolTracingSubscriber;
use AiObservability\Feedback\Feedback;
use AiObservability\Steering\HubConfigLoader;
use AiObservability\Steering\SteeringInputProcessor;
use AiObservability\Steering\SteeringProvider;
use AiObservability\Tracing\MessageFormatter;
use AiObservability\Tracing\SpanStack;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use Symfony\AI\Agent\InputProcessorInterface;
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
                ->arrayNode('agents')
                    ->info('Model, system prompt and options imposed on an agent, keyed by agent name (AgentInterface::getName()). The hub overrides them when connected.')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('model')->defaultNull()->end()
                            ->scalarNode('system_prompt')->defaultNull()->end()
                            ->scalarNode('prompt_version')->defaultNull()->end()
                            ->arrayNode('options')
                                ->info('Options merged over the ones of the call, e.g. temperature or max_tokens.')
                                ->normalizeKeys(false)
                                ->variablePrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('experiments')
                    ->info('A/B tests keyed by experiment id. Each conversation gets one variant, which may change the system prompt, the model and the options.')
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
                                        ->integerNode('weight')->defaultValue(1)->min(0)->end()
                                        ->scalarNode('model')->defaultNull()->end()
                                        ->scalarNode('system_prompt')->defaultNull()->end()
                                        ->scalarNode('prompt_version')->defaultNull()->end()
                                        ->arrayNode('options')
                                            ->info('Options merged over the ones of the call, e.g. temperature or max_tokens.')
                                            ->normalizeKeys(false)
                                            ->variablePrototype()->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('hub')
                    ->info('Reads the agent settings and experiments managed in the hub. Without a url, only the ones above apply.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('url')->info('Base url of the hub, e.g. "%env(AI_HUB_URL)%".')->defaultNull()->end()
                        ->scalarNode('token')->info('Bearer token of the hub API (HUB_STEERING_TOKEN on the hub side).')->defaultNull()->end()
                        ->integerNode('cache_ttl')->info('Seconds a configuration read from the hub is kept before asking again.')->defaultValue(60)->min(1)->end()
                        ->floatNode('timeout')->info('Seconds to wait for the hub, the last configuration received is used beyond.')->defaultValue(2.0)->min(0.1)->end()
                        ->scalarNode('http_client')->defaultValue('http_client')->end()
                        ->scalarNode('cache')->defaultValue('cache.app')->end()
                    ->end()
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
     * @param array{enabled: bool, capture_content: bool, tracer_provider: ?string, agents: array<string, array<string, mixed>>, experiments: array<string, array<string, mixed>>, hub: array{url: ?string, token: ?string, cache_ttl: int, timeout: float, http_client: string, cache: string}, user_id_resolver: ?string} $config
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

        $hub = null;
        if (null !== $config['hub']['url']) {
            $services->set('ai_observability.hub_config_loader', HubConfigLoader::class)
                ->args([
                    service($config['hub']['http_client']),
                    service($config['hub']['cache']),
                    $config['hub']['url'],
                    $config['hub']['token'],
                    $config['hub']['cache_ttl'],
                    $config['hub']['timeout'],
                    service('logger')->nullOnInvalid(),
                ])
                ->tag('monolog.logger', ['channel' => 'ai_observability']);
            $hub = service('ai_observability.hub_config_loader');
        }

        $services->set('ai_observability.steering_provider', SteeringProvider::class)
            ->args([['agents' => $config['agents'], 'experiments' => $config['experiments']], $hub]);

        if (interface_exists(InputProcessorInterface::class)) {
            // Before SystemPromptInputProcessor (-30) and MemoryInputProcessor (-40) of symfony/ai-bundle, on every agent
            $services->set('ai_observability.steering_input_processor', SteeringInputProcessor::class)
                ->args([service('ai_observability.steering_provider'), service('ai_observability.span_stack')])
                ->tag('ai.agent.input_processor', ['priority' => 0]);
            $services->alias(SteeringInputProcessor::class, 'ai_observability.steering_input_processor');
        }

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
