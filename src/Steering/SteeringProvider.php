<?php

namespace AiObservability\Steering;

/**
 * The configuration in force: the one of the bundle, overridden agent by agent by the hub when it is connected.
 */
final readonly class SteeringProvider
{
    private SteeringConfig $static;

    /**
     * @param array<mixed> $config agents and experiments of the bundle configuration
     */
    public function __construct(
        array $config,
        private ?HubConfigLoader $hub = null,
    ) {
        $this->static = SteeringConfig::fromArray($config);
    }

    public function config(): SteeringConfig
    {
        $hub = $this->hub?->load();

        return null === $hub ? $this->static : $this->static->merge($hub);
    }
}
