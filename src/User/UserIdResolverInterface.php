<?php

namespace AiObservability\User;

/**
 * Tells who the end user of the current request is, for the user.id attribute that retention is computed on.
 * Return a pseudonymous id, never an email or a name: the spans leave the application.
 */
interface UserIdResolverInterface
{
    public function resolve(): ?string;
}
