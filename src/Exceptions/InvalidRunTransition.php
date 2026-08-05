<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions;

use Pandora\Pandora\Runs\Enums\RunState;

/**
 * An illegal state transition was attempted.
 *
 * This throws rather than no-ops on purpose: a silently ignored transition
 * leaves a run wedged in a state nobody can explain later.
 */
final class InvalidRunTransition extends PandoraException
{
    public static function between(RunState $from, RunState $to, string $runId): self
    {
        return new self("Run [{$runId}] cannot transition from [{$from->value}] to [{$to->value}].");
    }

    public static function terminal(RunState $from, string $runId): self
    {
        return new self("Run [{$runId}] is already in terminal state [{$from->value}].");
    }
}
