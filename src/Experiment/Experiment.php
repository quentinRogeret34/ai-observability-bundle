<?php

namespace AiObservability\Experiment;

/**
 * A/B test of system prompts on one agent: each conversation gets one variant, picked by weight.
 */
final readonly class Experiment
{
    /**
     * @param non-empty-array<string, array{system_prompt: string, weight: int}> $variants
     */
    public function __construct(
        public string $id,
        public string $agent,
        private array $variants,
    ) {
        if (array_sum(array_column($variants, 'weight')) <= 0) {
            throw new \InvalidArgumentException(\sprintf('The variants of experiment "%s" need a total weight above 0.', $id));
        }
    }

    /**
     * The same key always gets the same variant, so a conversation keeps its prompt from one turn to the next.
     */
    public function assign(string $key): string
    {
        $point = hexdec(substr(hash('xxh3', $this->id.'|'.$key), 0, 8)) % array_sum(array_column($this->variants, 'weight'));

        foreach ($this->variants as $name => $variant) {
            if ($point < $variant['weight']) {
                return $name;
            }

            $point -= $variant['weight'];
        }

        return array_key_last($this->variants);
    }

    public function systemPrompt(string $variant): string
    {
        return $this->variants[$variant]['system_prompt'];
    }
}
