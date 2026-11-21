<?php

namespace AiObservability\User;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The identifier of the logged-in user, hashed with a secret so the hub never sees it in clear.
 */
final readonly class SecurityUserIdResolver implements UserIdResolverInterface
{
    public function __construct(
        private ?TokenStorageInterface $tokenStorage,
        #[\SensitiveParameter]
        private string $secret,
    ) {
    }

    public function resolve(): ?string
    {
        $identifier = $this->tokenStorage?->getToken()?->getUserIdentifier();

        return null === $identifier || '' === $identifier ? null : substr(hash_hmac('sha256', $identifier, $this->secret), 0, 32);
    }
}
