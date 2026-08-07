<?php

declare(strict_types=1);

namespace Pandora\Runs\Events;

use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;

/**
 * An internal domain event -- NOT a broadcast payload.
 *
 * Broadcast DTOs live in Realtime\Events and are redacted; this carries the
 * live model for in-process listeners and must never be broadcast directly.
 */
final readonly class RunStateChanged
{
    public function __construct(
        public Run $run,
        public RunState $from,
        public RunState $to,
    ) {}
}
