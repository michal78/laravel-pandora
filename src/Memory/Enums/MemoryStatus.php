<?php

declare(strict_types=1);

namespace Pandora\Memory\Enums;

/**
 * Whether a memory may be said out loud.
 *
 * Only `Active` is ever retrieved. A `Suggested` item exists, is visible to a
 * human in the control center, and is invisible to every agent -- which is the
 * point: an agent that could read back its own unapproved suggestion has
 * approved it itself.
 */
enum MemoryStatus: string
{
    case Active = 'active';

    /** Written, awaiting a human. Never retrieved. */
    case Suggested = 'suggested';

    /** A human said no. Kept, so the same suggestion is not re-proposed forever. */
    case Rejected = 'rejected';

    /** Past its `expires_at` and swept. Never retrieved. */
    case Expired = 'expired';

    public function retrievable(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suggested => 'Awaiting review',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }
}
