<?php

declare(strict_types=1);

namespace Pandora\Approvals\Enums;

/**
 * How far one approval decision reaches.
 *
 * The scopes are ordered by how much they trade safety for convenience, and
 * `remembered` can be switched off entirely by a deployment that should not
 * have the option.
 */
enum ApprovalScope: string
{
    /** This call, once. The safe default. */
    case Once = 'once';

    /** Every further call to this tool within this run. */
    case Run = 'run';

    /** Every further call to this tool by this actor, until revoked. */
    case Remembered = 'remembered';

    public function label(): string
    {
        return match ($this) {
            self::Once => 'Just this once',
            self::Run => 'For the rest of this run',
            self::Remembered => 'Remember this choice',
        };
    }

    /**
     * Whether a decision at this scope covers calls beyond the one it was
     * made about. Anything that does is worth an audit entry of its own.
     */
    public function outlivesTheCall(): bool
    {
        return $this !== self::Once;
    }
}
