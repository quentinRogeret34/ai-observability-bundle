<?php

namespace AiObservability\DependencyInjection;

use AiObservability\Agent\TracedAgent;
use AiObservability\Profiler\TraceableTracer;
use AiObservability\User\SecurityUserIdResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Decorates every agent declared through symfony/ai-bundle with TracedAgent, fills user.id from the logged-in user
 * when SecurityBundle is there and no other resolver was configured, and shows the spans in the profiler when it is on.
 */
final class AgentTracingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('ai_observability.tracer')) {
            return;
        }

        if (!$container->has('ai_observability.user_id_resolver') && $container->has('security.token_storage') && $container->hasParameter('kernel.secret')) {
            $container->setDefinition('ai_observability.user_id_resolver', new Definition(SecurityUserIdResolver::class, [
                new Reference('security.token_storage'),
                '%kernel.secret%',
            ]));
        }

        if ($container->hasDefinition('profiler')) {
            $container->setDefinition('ai_observability.tracer.traceable', (new Definition(TraceableTracer::class))
                ->setDecoratedService('ai_observability.tracer')
                ->setArguments([new Reference('.inner'), new Reference('ai_observability.data_collector')]));
        }

        foreach (array_keys($container->findTaggedServiceIds('ai.agent')) as $agent) {
            $container->setDefinition($agent.'.ai_observability', (new Definition(TracedAgent::class))
                ->setDecoratedService($agent, priority: -2048)
                ->setArguments([
                    new Reference('.inner'),
                    new Reference('ai_observability.tracer'),
                    new Reference('ai_observability.span_stack'),
                    new Reference('ai_observability.user_id_resolver', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ]));
        }
    }
}
