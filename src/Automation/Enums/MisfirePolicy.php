<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation\Enums;

/**
 * What happens to occurrences that were due while nothing was running.
 *
 * The default is `skip`. A worker down for six hours must not come back to
 * three hundred and sixty queued runs, all of them stale, all of them costing
 * money to discover they are stale.
 */
enum MisfirePolicy: string
{
    /** Forget them. Schedule from now. */
    case Skip = 'skip';

    /** Fire one catch-up occurrence, then resume the normal schedule. */
    case RunOnce = 'run_once';

    /**
     * Fire every missed occurrence, up to `automation.misfire.max_catch_up`.
     * Bounded on purpose -- an unbounded catch-up is the outage twice.
     */
    case RunAll = 'run_all';

    public function label(): string
    {
        return match ($this) {
            self::Skip => 'Skip missed runs',
            self::RunOnce => 'Catch up once',
            self::RunAll => 'Catch up all (bounded)',
        };
    }
}
