<?php

declare(strict_types=1);

namespace Pandora\Pandora\Runs\Enums;

/**
 * How much an agent may do without a human in the loop.
 *
 * Reproduces the useful part of a proactive agent while removing the
 * ambiguity. See docs/adr/0009-bounded-autonomy.md.
 */
enum AutonomyLevel: string
{
    /** May read and report. No mutating tool ever runs. */
    case ObserveOnly = 'observe_only';

    /** May propose actions as pending observations. Still executes nothing mutating. */
    case Suggest = 'suggest';

    /** May execute, but every mutating tool call requires human approval. */
    case ActWithApproval = 'act_with_approval';

    /** May execute anything its tool policy already permits, without asking. */
    case ActWithinPolicy = 'act_within_policy';

    public function label(): string
    {
        return match ($this) {
            self::ObserveOnly => 'Observe only',
            self::Suggest => 'Suggest actions',
            self::ActWithApproval => 'Act with approval',
            self::ActWithinPolicy => 'Act within policy',
        };
    }

    public function allowsMutation(): bool
    {
        return in_array($this, [self::ActWithApproval, self::ActWithinPolicy], true);
    }

    /**
     * How much rope, as a number. Only meaningful next to another level.
     */
    public function weight(): int
    {
        return match ($this) {
            self::ObserveOnly => 0,
            self::Suggest => 1,
            self::ActWithApproval => 2,
            self::ActWithinPolicy => 3,
        };
    }

    /**
     * The more restrictive of two levels.
     *
     * Lives here because three things need it -- an automation against its
     * agent, an event binding against its agent, and the manual-run button --
     * and the one that reimplemented it wrongly would be the interesting one.
     * The direction is never negotiable: a thing that schedules an agent may
     * ask for less than the agent has and never for more, or scheduling
     * becomes privilege escalation. See ADR-0009.
     */
    public function narrowerOf(self $other): self
    {
        return $this->weight() <= $other->weight() ? $this : $other;
    }
}
