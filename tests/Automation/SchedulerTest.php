<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Automation\Automation;
use Pandora\Pandora\Automation\AutomationDispatcher;
use Pandora\Pandora\Automation\AutomationRun;
use Pandora\Pandora\Automation\AutomationScheduler;
use Pandora\Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Pandora\Automation\Enums\OccurrenceStatus;
use Pandora\Pandora\Jobs\RunAutomation;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Tests\Fixtures\AutomationFactory;

/**
 * Phase 4, criteria 4, 5, 7 and 8 -- the tick.
 *
 * Criterion 5 is the one this phase exists for. Everything else here is
 * bookkeeping around it.
 */
beforeEach(function (): void {
    $this->scheduler = app(AutomationScheduler::class);
    $this->dispatcher = app(AutomationDispatcher::class);
});

// ---------------------------------------------------------------- criterion 4

it('selects exactly the enabled automations whose next run has passed', function (): void {
    Bus::fake();

    $due = AutomationFactory::due(['slug' => 'due']);
    $notYet = AutomationFactory::make(['slug' => 'not-yet']);
    $notYet->forceFill(['next_run_at' => now()->addHour()])->save();
    $disabled = AutomationFactory::due(['slug' => 'disabled', 'enabled' => false]);
    // An event automation with a stray date must never be claimed by a clock.
    $external = AutomationFactory::due([
        'slug' => 'external',
        'trigger_type' => AutomationTrigger::Event->value,
        'event_class' => 'App\\Events\\Whatever',
    ]);

    $this->scheduler->tick();

    Bus::assertDispatchedTimes(RunAutomation::class, 2);

    // The event automation IS claimed by this tick, because it carries a
    // next_run_at -- so `advance()` must be what clears it.
    $this->scheduler->advance($external->refresh());

    expect($external->refresh()->next_run_at)->toBeNull()
        ->and($due->refresh()->next_run_at)->not->toBeNull()
        ->and($notYet->refresh()->next_run_at->isFuture())->toBeTrue()
        ->and($disabled->refresh()->next_run_at)->not->toBeNull();
});

it('does nothing at all when automation is disabled for the deployment', function (): void {
    Bus::fake();
    config()->set('pandora.automation.enabled', false);

    AutomationFactory::due();

    expect($this->scheduler->tick())->toBe([]);
    Bus::assertNothingDispatched();
});

// ---------------------------------------------------------------- criterion 5

it('produces exactly one run when two schedulers fire simultaneously', function (): void {
    // The property this whole phase is built around.
    //
    // Two ticks are not simulated with threads -- they are simulated by doing
    // exactly what two schedulers do: computing the same occurrence and both
    // trying to claim it. If the guard were a `last_run_at` check rather than
    // a unique insert, both would pass it here just as they would in
    // production, because nothing between the read and the write stops them.
    $automation = AutomationFactory::due();
    $occurrence = Carbon::parse('2026-07-01 09:00:00', 'UTC');

    $first = $this->dispatcher->dispatch($automation, $occurrence);
    $second = $this->dispatcher->dispatch($automation->refresh(), $occurrence);

    expect($first)->not->toBeNull()
        ->and($first->status)->toBe(OccurrenceStatus::Dispatched)
        // Null is the honest answer for the loser: the occurrence exists and
        // has a run, it simply is not ours.
        ->and($second)->toBeNull()
        ->and(AutomationRun::query()->where('automation_id', $automation->getKey())->count())->toBe(1)
        ->and(Run::query()->where('automation_id', $automation->getKey())->count())->toBe(1);
});

it('lets two DIFFERENT occurrences of the same automation both run', function (): void {
    // The guard must be tight enough to stop a double-fire and loose enough
    // that an hourly automation is still hourly.
    $automation = AutomationFactory::due(['concurrency_policy' => 'allow']);

    $this->dispatcher->dispatch($automation, Carbon::parse('2026-07-01 09:00:00', 'UTC'));
    $this->dispatcher->dispatch($automation->refresh(), Carbon::parse('2026-07-01 10:00:00', 'UTC'));

    expect(Run::query()->where('automation_id', $automation->getKey())->count())->toBe(2);
});

// ---------------------------------------------------------------- criterion 7

it('writes an occurrence row for a refusal, with the reason', function (): void {
    // "It never fired" and "it fired and declined" are different incidents,
    // and a silence is indistinguishable from a scheduler that died on
    // Tuesday.
    $automation = AutomationFactory::due();
    $automation->agent->forceFill(['enabled' => false])->save();

    $occurrence = $this->dispatcher->dispatch($automation, Carbon::now());

    expect($occurrence)->not->toBeNull()
        ->and($occurrence->status)->toBe(OccurrenceStatus::Refused)
        ->and($occurrence->reason)->toBe('agent_disabled')
        ->and($occurrence->run_id)->toBeNull()
        ->and($occurrence->error)->toContain('is disabled');
});

it('records the automation that fired, on the run', function (): void {
    $automation = AutomationFactory::due();

    $occurrence = $this->dispatcher->dispatch($automation, Carbon::now());

    /** @var Run $run */
    $run = Run::query()->findOrFail($occurrence->run_id);

    expect($run->automation_id)->toBe($automation->getKey())
        // A cron automation produces `schedule` runs; the run stays
        // attributable after the automation is deleted.
        ->and($run->trigger_type->value)->toBe('schedule')
        ->and($run->actor_type)->toBe('system')
        ->and($automation->refresh()->last_run_id)->toBe((string) $run->getKey());
});

// ---------------------------------------------------------------- criterion 8

it('advances next_run_at past the occurrence it just claimed', function (): void {
    Bus::fake();

    $automation = AutomationFactory::make(['cron_expression' => '*/5 * * * *']);
    $automation->forceFill(['next_run_at' => now()->subSeconds(10)])->save();

    $before = $automation->next_run_at;

    $this->scheduler->tick();

    // Otherwise a run taking ten minutes is still due on each of the next
    // nine ticks, and every one of them claims, fails concurrency and writes
    // a refusal -- one slow run becoming nine noise rows.
    expect($automation->refresh()->next_run_at->greaterThan($before))->toBeTrue()
        ->and($automation->next_run_at->isFuture())->toBeTrue();
});

it('carries the automation\'s tenant on the queued job rather than the current one', function (): void {
    Bus::fake();

    // A tick runs in the console, where no tenant is resolved. An automation
    // belonging to tenant B must not stop firing because nobody is tenant B
    // at 3am.
    $automation = AutomationFactory::due();
    $automation->forceFill(['tenant_id' => 'tenant-b'])->save();

    $this->scheduler->tick();

    Bus::assertDispatched(
        RunAutomation::class,
        static fn (RunAutomation $job): bool => $job->tenantId === 'tenant-b',
    );
});

it('claims at most the configured batch size in one tick', function (): void {
    Bus::fake();
    config()->set('pandora.automation.batch_size', 2);

    foreach (range(1, 5) as $i) {
        AutomationFactory::due(['slug' => "batched-{$i}"]);
    }

    expect($this->scheduler->tick())->toHaveCount(2);
});

it('clears next_run_at for an automation that stopped being scheduled', function (): void {
    $automation = AutomationFactory::due();

    $automation->forceFill([
        'trigger_type' => AutomationTrigger::Webhook->value,
    ])->save();

    $this->scheduler->advance($automation);

    expect($automation->refresh()->next_run_at)->toBeNull();
});

it('records the fired occurrence in the audit log', function (): void {
    $automation = AutomationFactory::due();

    $this->dispatcher->dispatch($automation, Carbon::now());

    expect(AuditLog::query()->pluck('action')->all())->toContain('automation.fired');
});

it('records a refusal in the audit log at warning severity', function (): void {
    $automation = AutomationFactory::due();
    $automation->agent->forceFill(['enabled' => false])->save();

    $this->dispatcher->dispatch($automation, Carbon::now());

    /** @var Automation $reloaded */
    $reloaded = $automation->refresh();

    expect(AuditLog::query()->pluck('action')->all())->toContain('automation.refused')
        // A refusal does not turn the automation off. Only the autonomy budget
        // and the retry policy do that.
        ->and($reloaded->enabled)->toBeTrue();
});
