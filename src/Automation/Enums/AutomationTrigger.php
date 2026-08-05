<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation\Enums;

/**
 * What wakes an automation.
 *
 * Distinct from `TriggerType`, which records what caused a *run*. An
 * automation of trigger `cron` produces runs of trigger type `schedule`; a
 * `heartbeat` automation produces `heartbeat` runs. Keeping the two enums
 * apart is what lets a run be attributed without the automation existing any
 * more.
 */
enum AutomationTrigger: string
{
    /** Fires once, at `run_at`, and then schedules nothing further. */
    case OneOff = 'one_off';

    /** A cron expression, evaluated in the automation's own timezone. */
    case Cron = 'cron';

    /** Every `interval_seconds`, from the last occurrence. */
    case Interval = 'interval';

    /** A Laravel event class. */
    case Event = 'event';

    /** An HTTP POST to the automation's signed endpoint. */
    case Webhook = 'webhook';

    /** A bounded recurring wake for an agent to decide whether anything needs doing. */
    case Heartbeat = 'heartbeat';

    /**
     * Whether the scheduler owns this trigger.
     *
     * Event and webhook automations are woken by something outside; they have
     * no `next_run_at` and the scheduler must never claim them.
     */
    public function isScheduled(): bool
    {
        return in_array($this, [self::OneOff, self::Cron, self::Interval, self::Heartbeat], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::OneOff => 'One-off',
            self::Cron => 'Cron',
            self::Interval => 'Interval',
            self::Event => 'Event',
            self::Webhook => 'Webhook',
            self::Heartbeat => 'Heartbeat',
        };
    }
}
