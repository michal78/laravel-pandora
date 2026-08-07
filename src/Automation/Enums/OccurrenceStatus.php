<?php

declare(strict_types=1);

namespace Pandora\Automation\Enums;

/**
 * What became of one occurrence of an automation.
 *
 * A refused occurrence is still a row. "It never fired" and "it fired and
 * declined to do anything" are different incidents, and a silence cannot be
 * told apart from a scheduler that stopped running a week ago.
 */
enum OccurrenceStatus: string
{
    /** Claimed, not yet dispatched. */
    case Claimed = 'claimed';

    /** A run was created. */
    case Dispatched = 'dispatched';

    /** The condition said no. */
    case Skipped = 'skipped';

    /** A policy said no -- concurrency, autonomy budget, disabled agent. */
    case Refused = 'refused';

    /** The dispatch itself failed. Distinct from the run failing. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Claimed => 'Claimed',
            self::Dispatched => 'Dispatched',
            self::Skipped => 'Skipped',
            self::Refused => 'Refused',
            self::Failed => 'Failed',
        };
    }
}
