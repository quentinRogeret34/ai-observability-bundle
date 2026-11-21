<?php

namespace AiObservability\Steering;

/**
 * The settings applied to one agent run, with the experiment variant they come from.
 */
final readonly class Resolution
{
    public function __construct(
        public AgentSettings $settings,
        public ?string $experiment = null,
        public ?string $variant = null,
    ) {
    }
}
