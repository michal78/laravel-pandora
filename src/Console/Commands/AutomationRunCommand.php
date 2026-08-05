<?php

declare(strict_types=1);

namespace Pandora\Pandora\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Automation\Automation;
use Pandora\Pandora\Automation\AutomationDispatcher;
use Pandora\Pandora\Automation\AutomationRun;
use Pandora\Pandora\Automation\Enums\OccurrenceStatus;

/**
 * Fire an automation now.
 *
 * A manual run bypasses the schedule and the misfire policy -- an operator
 * pressing this has decided the timing. It does NOT bypass the autonomy clamp,
 * the condition or the concurrency policy: those describe what the automation
 * may do, not when, and "I ran it by hand" is not permission for the agent to
 * exceed its level.
 *
 * The occurrence is stamped `now`, so it gets its own idempotency key and
 * cannot collide with the scheduled occurrence it sits next to.
 */
final class AutomationRunCommand extends Command
{
    protected $signature = 'pandora:automation:run
                            {automation : The automation slug}
                            {--sync : Execute the run in this process and wait}';

    protected $description = 'Fire one occurrence of an automation immediately.';

    public function handle(AutomationDispatcher $dispatcher): int
    {
        /** @var string $slug */
        $slug = $this->argument('automation');

        /** @var Automation|null $automation */
        $automation = Automation::query()->where('slug', $slug)->first();

        if ($automation === null) {
            $this->components->error("No automation named [{$slug}].");

            return self::FAILURE;
        }

        $occurrence = $dispatcher->dispatch(
            automation: $automation,
            occurrence: Carbon::now(),
            payload: ['manual' => true],
            synchronous: $this->option('sync') === true,
        );

        if ($occurrence === null) {
            $this->components->warn('That occurrence was already claimed. Nothing was started.');

            return self::SUCCESS;
        }

        return $this->report($occurrence);
    }

    private function report(AutomationRun $occurrence): int
    {
        if ($occurrence->status === OccurrenceStatus::Dispatched) {
            $this->components->info("Run [{$occurrence->run_id}] created.");

            return self::SUCCESS;
        }

        // A refusal is reported as a failure so a script can act on it. It is
        // not an error -- the automation worked -- but "nothing happened" and
        // "it ran" must not share an exit code.
        $this->components->warn(sprintf(
            'No run was created (%s): %s',
            $occurrence->status->value,
            $occurrence->error ?? $occurrence->reason ?? 'no reason recorded',
        ));

        return self::FAILURE;
    }
}
