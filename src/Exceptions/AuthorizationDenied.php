<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

final class AuthorizationDenied extends PandoraException
{
    public static function forAgent(string $slug): self
    {
        return new self("The current actor is not authorized to run agent [{$slug}].");
    }

    public static function forAbility(string $ability): self
    {
        return new self("The current actor lacks ability [{$ability}].");
    }

    public function userMessage(): string
    {
        return 'You are not authorized to perform this action.';
    }
}
