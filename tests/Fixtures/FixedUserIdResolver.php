<?php

namespace AiObservability\Tests\Fixtures;

use AiObservability\User\UserIdResolverInterface;

final class FixedUserIdResolver implements UserIdResolverInterface
{
    public function resolve(): ?string
    {
        return 'user-42';
    }
}
