<?php

namespace AiObservability\DependencyInjection;

use AiObservability\Agent\TracedAgent;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Decorates every agent declared through symfony/ai-bundle with TracedAgent, which also applies its experiment.
 */
final class AgentTracingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('ai_observability.tracer')) {
            return;
        }

        foreach (array_keys($container->findTaggedServiceIds('ai.agent')) as $agent) {
            $container->setDefinition($agent.'.ai_observability', (new Definition(TracedAgent::class))
                ->setDecoratedService($agent, priority: -2048)
                ->setArguments([
                    new Reference('.inner'),
                    new Reference('ai_observability.tracer'),
                    new Reference('ai_observability.span_stack'),
                    new Reference('ai_observability.experiments'),
                ]));
        }
    }
}
