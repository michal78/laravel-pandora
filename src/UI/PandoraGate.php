<?php

declare(strict_types=1);

namespace Pandora\Pandora\UI;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;

/**
 * Ability-name lookup for UI and policy checks.
 *
 * Ability names are configurable, so nothing should hard-code the string
 * 'pandora.access'. This resolves the configured name for a key.
 */
final class PandoraGate
{
    /** @var array<string, string> */
    private static array $abilities = [];

    /**
     * @param array<string, string> $abilities
     */
    public static function useAbilities(array $abilities): void
    {
        self::$abilities = $abilities;
    }

    public static function ability(string $key): string
    {
        return self::$abilities[$key] ?? 'pandora.'.$key;
    }

    public static function allows(string $key, mixed ...$arguments): bool
    {
        return Gate::allows(self::ability($key), $arguments === [] ? null : $arguments);
    }

    /**
     * Check an ability for a SPECIFIC user rather than the logged-in one.
     *
     * A queue worker has no authenticated user, and an approval resolved
     * through the API is decided by whoever holds the token -- neither can
     * rely on the ambient guard.
     */
    public static function forUser(Authorizable $user, string $key, mixed ...$arguments): bool
    {
        return Gate::forUser($user)->allows(self::ability($key), $arguments === [] ? null : $arguments);
    }

    public static function denies(string $key, mixed ...$arguments): bool
    {
        return ! self::allows($key, ...$arguments);
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorize(string $key, mixed ...$arguments): void
    {
        Gate::authorize(self::ability($key), $arguments === [] ? [] : $arguments);
    }
}
