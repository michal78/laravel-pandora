<?php

declare(strict_types=1);

namespace Pandora\Memory\Enums;

/**
 * How much trouble this memory can cause if it is said to the wrong person.
 *
 * Sensitivity decides whether a write becomes an active memory or a suggestion
 * a human must approve. It deliberately does not affect retrieval: an approved
 * sensitive memory is as retrievable as any other, because a fact nobody can
 * retrieve is a fact nobody needed to approve.
 */
enum MemorySensitivity: string
{
    /** Ordinary. Stored directly. */
    case Normal = 'normal';

    /** A claim about a person, health, finance, or anything an actor would
     *  object to seeing repeated. Requires approval before it is active. */
    case Sensitive = 'sensitive';

    /** Must never be stored at all -- credentials, tokens, keys. The redactor
     *  removes these before persistence; this case exists so a classifier can
     *  say so explicitly rather than by silence. */
    case Restricted = 'restricted';

    public function requiresApproval(): bool
    {
        return $this === self::Sensitive;
    }

    public function storable(): bool
    {
        return $this !== self::Restricted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Sensitive => 'Sensitive',
            self::Restricted => 'Must not be stored',
        };
    }
}
