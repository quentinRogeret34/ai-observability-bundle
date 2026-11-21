<?php

namespace AiObservability\Experiment;

final class Experiments
{
    /** @var array<string, Experiment> */
    private array $byAgent = [];

    /**
     * @param array<string, array{agent: string, variants: non-empty-array<string, array{system_prompt: string, weight: int}>}> $config
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $id => $experiment) {
            if (isset($this->byAgent[$experiment['agent']])) {
                throw new \InvalidArgumentException(\sprintf('Experiments "%s" and "%s" both target agent "%s": run one at a time.', $this->byAgent[$experiment['agent']]->id, $id, $experiment['agent']));
            }

            $this->byAgent[$experiment['agent']] = new Experiment($id, $experiment['agent'], $experiment['variants']);
        }
    }

    public function forAgent(string $agent): ?Experiment
    {
        return $this->byAgent[$agent] ?? null;
    }
}
