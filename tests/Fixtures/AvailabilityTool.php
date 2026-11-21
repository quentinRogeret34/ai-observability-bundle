<?php

namespace AiObservability\Tests\Fixtures;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('search_availability', 'Lists the rooms available on a date')]
final class AvailabilityTool
{
    public function __invoke(string $date): string
    {
        if ('2000-01-01' === $date) {
            throw new \RuntimeException('Date in the past.');
        }

        return 'Room 12 is available on '.$date;
    }
}
