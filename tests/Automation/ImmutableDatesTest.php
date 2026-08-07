<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Audit\AuditLog;
use Pandora\Automation\Automation;
use Pandora\Automation\AutomationDispatcher;
use Pandora\Automation\AutomationRun;
use Pandora\Automation\AutomationScheduler;
use Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Automation\Schedule\NextRun;
use Pandora\Tests\Fixtures\AutomationFactory;
use Pandora\UI\Livewire\AutomationDetail;
use Pandora\UI\Livewire\AutomationsIndex;

/**
 * Pandora works in an application that uses immutable dates.
 *
 * `Date::use(CarbonImmutable::class)` is a documented, common and entirely
 * reasonable thing for a host to do -- it is in the default Laravel skeleton's
 * `AppServiceProvider` as a suggestion. It changes what model date casts
 * return: `CarbonImmutable`, which is NOT an `Illuminate\Support\Carbon`.
 *
 * Phase 4 typed its date parameters and returns as `Illuminate\Support\Carbon`
 * and therefore fatally errored on the Automations page of any such host. The
 * package suite could not see it because the test application does not set it.
 * Found by a human opening the page — which is the entire argument for the
 * host walkthrough.
 *
 * Every date that crosses a Pandora boundary is now typed `CarbonInterface`,
 * which both implementations satisfy. This file is what stops that regressing.
 */
beforeEach(function (): void {
    Date::use(CarbonImmutable::class);

    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.automations.manage', static fn (): bool => true);

    $this->actingAsUser();
});

afterEach(function (): void {
    // Global state. Leaving it set would silently change the dates every
    // later test sees, and the failures would land somewhere unrelated.
    Date::useDefault();
});

it('confirms the host really is on immutable dates', function (): void {
    // Guards the guard. If a Laravel change made `Date::use()` stop affecting
    // model casts, every assertion below would pass while testing nothing --
    // which is precisely the failure mode this whole session started with.
    $automation = AutomationFactory::due();

    expect($automation->refresh()->next_run_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('renders the automations index', function (): void {
    // The exact failure a human hit: `schedulerLastSeen()` returning a
    // model's `last_run_at`.
    $automation = AutomationFactory::due();
    $automation->forceFill(['last_run_at' => now()->subMinutes(5)])->save();

    Livewire::test(AutomationsIndex::class)
        ->assertOk()
        ->assertSee('Nightly report');
});

it('renders the automation detail page and its tabs', function (): void {
    $automation = AutomationFactory::due();

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->assertOk()
        ->call('selectTab', 'schedule')
        ->assertOk()
        ->call('selectTab', 'history')
        ->assertOk();
});

it('computes a next occurrence from an immutable date', function (): void {
    $automation = AutomationFactory::make(['cron_expression' => '0 9 * * *']);

    $next = app(NextRun::class)->after($automation, CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'));

    expect($next)->not->toBeNull()
        ->and($next->utc()->format('H:i'))->toBe('09:00');
});

it('ticks the scheduler', function (): void {
    Bus::fake();

    AutomationFactory::due();

    expect(app(AutomationScheduler::class)->tick())->toHaveCount(1);
});

it('derives an occurrence key from an immutable date, without mutating it', function (): void {
    $at = CarbonImmutable::parse('2026-07-01 09:00:00', 'Europe/Copenhagen');

    $key = AutomationRun::keyFor('automation-abc', $at);

    expect($key)->toHaveLength(64)
        // The same instant in any zone is the same occurrence...
        ->and($key)->toBe(AutomationRun::keyFor('automation-abc', $at->utc()))
        // ...and computing it did not rewrite the caller's argument.
        ->and($at->timezoneName)->toBe('Europe/Copenhagen');
});

it('dispatches an occurrence', function (): void {
    $automation = AutomationFactory::due();

    $occurrence = app(AutomationDispatcher::class)->dispatch($automation, CarbonImmutable::now());

    expect($occurrence)->not->toBeNull()
        ->and($occurrence->run_id)->not->toBeNull();
});

it('does not report an unchanged date as a change', function (): void {
    // `!==` on two Carbon objects is identity comparison, so a date field
    // looked changed on every single save -- putting a spurious entry in the
    // audit log each time somebody edited a cron expression, and making the
    // per-tab diff useless for the one thing it exists to answer.
    //
    // Whole minutes, because the form field is `datetime-local` and cannot
    // express seconds. A stored date with seconds in it really does change
    // when saved through a minute-precision form, and reporting that is
    // correct -- it is only the identity comparison that was wrong.
    $automation = AutomationFactory::make([
        'trigger_type' => AutomationTrigger::OneOff->value,
        'cron_expression' => null,
        'run_at' => now()->addDay()->startOfMinute(),
    ]);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'schedule')
        ->call('startEditing')
        ->call('save')
        ->assertSee('No changes to save');

    expect(AuditLog::query()->where('action', 'automation.updated')->count())
        ->toBe(0);
});

it('still records a date that genuinely changed', function (): void {
    $automation = AutomationFactory::make([
        'trigger_type' => AutomationTrigger::OneOff->value,
        'cron_expression' => null,
        'run_at' => now()->addDay(),
    ]);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'schedule')
        ->call('startEditing')
        ->set('runAt', '2027-03-01T09:00')
        ->call('save')
        ->assertSee('Saved');

    /** @var Automation $reloaded */
    $reloaded = $automation->refresh();

    expect($reloaded->run_at->format('Y-m-d'))->toBe('2027-03-01');
});
