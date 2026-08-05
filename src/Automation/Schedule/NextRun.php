<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation\Schedule;

use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Automation\Automation;
use Pandora\Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Pandora\Exceptions\InvalidConfiguration;

/**
 * When an automation is next due.
 *
 * Every calculation happens in the automation's own timezone and the answer is
 * returned in UTC, because that is what the `next_run_at` column stores and
 * what the scheduler compares against.
 *
 * The timezone is not a nicety. A "9am daily" report configured by somebody in
 * Copenhagen, stored against a server in UTC, moves by an hour twice a year --
 * and the person who configured it experiences that as Pandora being
 * unreliable rather than as a timezone bug, because it is right for months at
 * a time. Cron expressions are therefore evaluated by `CronExpression` with an
 * explicit timezone, which is what makes a spring-forward occurrence happen
 * once rather than not at all.
 */
final class NextRun
{
    /**
     * The first occurrence strictly after `$from`, or null if there is none.
     *
     * Null is a real answer, not a failure: a one-off that has fired has no
     * next occurrence, and neither has an event or webhook automation, which
     * is precisely why the scheduler must never claim one.
     */
    public function after(Automation $automation, ?Carbon $from = null): ?Carbon
    {
        $from = ($from ?? Carbon::now())->copy();

        return match ($automation->trigger_type) {
            AutomationTrigger::OneOff => $this->oneOff($automation, $from),
            AutomationTrigger::Cron => $this->cron($automation, $from),
            AutomationTrigger::Interval, AutomationTrigger::Heartbeat => $this->interval($automation, $from),
            // Woken by something outside. No schedule, by construction.
            AutomationTrigger::Event, AutomationTrigger::Webhook => null,
        };
    }

    /**
     * Every occurrence in `($after, $until]`, capped.
     *
     * Used only by the `run_all` misfire policy. The cap is not a detail: an
     * unbounded catch-up after a six-hour outage is the outage twice, and the
     * second time it costs money.
     *
     * @return list<Carbon>
     */
    public function occurrencesBetween(Automation $automation, Carbon $after, Carbon $until, int $cap): array
    {
        $occurrences = [];
        $cursor = $after->copy();

        while (count($occurrences) < $cap) {
            $next = $this->after($automation, $cursor);

            if ($next === null || $next->greaterThan($until)) {
                break;
            }

            $occurrences[] = $next;
            $cursor = $next;
        }

        return $occurrences;
    }

    /**
     * Whether the schedule this automation describes can actually be computed.
     *
     * Called by the editor before saving. A cron expression that does not
     * parse would otherwise be stored, produce a null `next_run_at`, and
     * present as an automation that simply never runs -- the hardest kind of
     * failure to notice.
     */
    public function validate(Automation $automation): void
    {
        if ($automation->trigger_type === AutomationTrigger::Cron) {
            $expression = (string) $automation->cron_expression;

            if (! CronExpression::isValidExpression($expression)) {
                throw InvalidConfiguration::make("[{$expression}] is not a valid cron expression.");
            }
        }

        if ($this->usesInterval($automation) && ($automation->interval_seconds ?? 0) < 1) {
            throw InvalidConfiguration::make('An interval automation needs a positive interval_seconds.');
        }

        if ($automation->trigger_type === AutomationTrigger::OneOff && $automation->run_at === null) {
            throw InvalidConfiguration::make('A one-off automation needs a run_at.');
        }

        if ($automation->timezone !== '' && ! in_array($automation->timezone, timezone_identifiers_list(), true)) {
            throw InvalidConfiguration::make("[{$automation->timezone}] is not a known timezone.");
        }
    }

    private function oneOff(Automation $automation, Carbon $from): ?Carbon
    {
        $at = $automation->run_at;

        // Already fired, or scheduled for a moment that has passed and been
        // dealt with. Either way there is nothing further.
        return $at !== null && $at->greaterThan($from) ? $at->copy()->utc() : null;
    }

    private function cron(Automation $automation, Carbon $from): ?Carbon
    {
        $expression = (string) $automation->cron_expression;

        if (! CronExpression::isValidExpression($expression)) {
            return null;
        }

        // The timezone argument is the whole point of this method: without it
        // CronExpression answers in the server's zone and a daily schedule
        // drifts by an hour at every DST boundary.
        $next = (new CronExpression($expression))->getNextRunDate(
            $from->copy()->setTimezone($automation->timezone),
            0,
            false,
            $automation->timezone,
        );

        return Carbon::instance($next)->utc();
    }

    private function interval(Automation $automation, Carbon $from): ?Carbon
    {
        // A heartbeat may be expressed either way. Cron wins when present,
        // because somebody who wrote one meant it.
        if ($automation->cron_expression !== null && $automation->cron_expression !== '') {
            return $this->cron($automation, $from);
        }

        $seconds = $automation->interval_seconds ?? 0;

        if ($seconds < 1) {
            return null;
        }

        return $from->copy()->utc()->addSeconds($seconds);
    }

    private function usesInterval(Automation $automation): bool
    {
        return in_array($automation->trigger_type, [AutomationTrigger::Interval, AutomationTrigger::Heartbeat], true)
            && ($automation->cron_expression === null || $automation->cron_expression === '');
    }
}
