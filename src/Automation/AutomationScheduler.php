<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Automation\Enums\MisfirePolicy;
use Pandora\Pandora\Automation\Schedule\NextRun;
use Pandora\Pandora\Jobs\RunAutomation;

/**
 * The one thing a clock talks to.
 *
 * Driven by a single Laravel scheduler entry (`pandora:automation:tick`,
 * every minute) rather than one entry per automation. Automations live in the
 * database and change without a deploy; a Kernel that had to be edited to add
 * one would put every schedule change behind a release.
 *
 * The tick does as little as possible. It selects due rows, advances each
 * one's `next_run_at` past the occurrence it is about to hand off, and queues
 * a job. Everything expensive -- conditions, agents, models -- happens on a
 * worker, so a slow automation cannot delay the tick and make every other
 * automation late.
 *
 * Advancing `next_run_at` BEFORE dispatching is deliberate. A run that takes
 * ten minutes would otherwise still be due on the next nine ticks, and each
 * of those would claim, fail the concurrency check, and write a refusal --
 * turning one slow run into nine noise rows.
 */
final class AutomationScheduler
{
    public function __construct(
        private readonly NextRun $nextRun,
        private readonly Config $config,
    ) {}

    /**
     * One tick.
     *
     * Crosses tenants on purpose: a scheduler runs in the console, where no
     * tenant is resolved, and an automation belonging to tenant B must not
     * stop firing because tenant A happened to be current. Each dispatched job
     * carries its automation's tenant id and restores it on the worker.
     *
     * @return list<string> the occurrence keys handed off this tick
     */
    public function tick(?CarbonInterface $now = null): array
    {
        if ($this->config->get('pandora.automation.enabled', true) !== true) {
            return [];
        }

        $now ??= Carbon::now();

        /** @var int $batch */
        $batch = $this->config->get('pandora.automation.batch_size', 100);

        // Typed at the call site because the trait this comes from is generic
        // across every Pandora model and cannot name a concrete one.
        /** @var Builder<Automation> $query */
        $query = Automation::acrossAllTenants();

        $due = $query
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->limit($batch)
            ->get();

        $dispatched = [];

        foreach ($due as $automation) {
            foreach ($this->occurrencesFor($automation, $now) as $occurrence) {
                $key = AutomationRun::keyFor((string) $automation->getKey(), $occurrence);

                RunAutomation::dispatch(
                    automationId: (string) $automation->getKey(),
                    tenantId: $automation->tenant_id,
                    occurrence: $occurrence->toIso8601String(),
                    idempotencyKey: $key,
                );

                $dispatched[] = $key;
            }

            $this->advance($automation, $now);
        }

        return $dispatched;
    }

    /**
     * Recompute and store the next occurrence.
     *
     * Public because the editor needs it: an automation whose cron expression
     * was just changed has a `next_run_at` computed from the old one, and a
     * schedule that keeps the previous schedule until it next fires is the
     * kind of thing that gets described as "it ignored my change".
     */
    public function advance(Automation $automation, ?CarbonInterface $from = null): void
    {
        if (! $automation->isScheduled()) {
            $automation->forceFill(['next_run_at' => null])->save();

            return;
        }

        $automation->forceFill([
            'next_run_at' => $this->nextRun->after($automation, $from ?? Carbon::now()),
        ])->save();
    }

    /**
     * Which occurrences this tick actually hands off.
     *
     * Normally exactly one: the due occurrence. The misfire policy only comes
     * into play when `next_run_at` is further in the past than the grace
     * window, which means nothing was running -- a worker was down, or the
     * scheduler was.
     *
     * @return list<CarbonInterface>
     */
    private function occurrencesFor(Automation $automation, CarbonInterface $now): array
    {
        $due = $automation->next_run_at;

        if ($due === null) {
            return [];
        }

        /** @var int $grace */
        $grace = $this->config->get('pandora.automation.misfire.grace_seconds', 120);

        // On time, or close enough that calling it a misfire would make a slow
        // minute into an incident.
        if ($due->diffInSeconds($now, absolute: true) <= $grace || $due->greaterThan($now)) {
            return [$due];
        }

        return match ($automation->misfire_policy) {
            // Forget what was missed. A worker down for six hours must not
            // come back to 360 stale runs, all of them costing money to
            // discover they are stale.
            MisfirePolicy::Skip => [],

            // One catch-up, dated now rather than then: the thing it was
            // supposed to do, it should do -- once.
            MisfirePolicy::RunOnce => [$due],

            MisfirePolicy::RunAll => $this->catchUp($automation, $due, $now),
        };
    }

    /**
     * @return list<CarbonInterface>
     */
    private function catchUp(Automation $automation, CarbonInterface $due, CarbonInterface $now): array
    {
        /** @var int $cap */
        $cap = $this->config->get('pandora.automation.misfire.max_catch_up', 12);

        // The due occurrence itself, plus everything between it and now. The
        // cap is why this is bounded: an unbounded catch-up after an outage is
        // the outage twice, and the second time it is self-inflicted.
        return [$due, ...$this->nextRun->occurrencesBetween($automation, $due, $now, max(0, $cap - 1))];
    }
}
