<?php

declare(strict_types=1);

namespace Pandora\UI;

/**
 * Whether a built-but-unreleased surface is reachable.
 *
 * A feature held back is not a feature removed. The engine behind a disabled
 * flag stays in the codebase and stays under test, because the alternative --
 * deleting it and restoring it a phase later -- is how tested code turns into
 * untested code. What the flag withdraws is the way in.
 *
 * Distinct from `PandoraGate`, which answers whether *this person* may reach
 * something that exists. This answers whether it exists to be reached at all,
 * and no ability grants past it.
 */
final class Feature
{
    public static function enabled(string $key): bool
    {
        return (bool) config('pandora.features.'.$key, false);
    }

    public static function disabled(string $key): bool
    {
        return ! self::enabled($key);
    }
}
