<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Automation\Automation;
use Pandora\Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Pandora\Automation\Enums\ObservationStatus;
use Pandora\Pandora\Automation\Observation;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Pandora\Tests\Fixtures\AgentFactory;
use Pandora\Pandora\Tests\Fixtures\AutomationFactory;
use Pandora\Pandora\UI\Livewire\AutomationsIndex;

/**
 * Phase 4 -- the Automations index, and the goal queue that sits under it.
 *
 * Two levels, like every other page: `pandora.access` reads the roster,
 * because somebody looking at a run wants to know what started it.
 * Everything that changes anything needs `pandora.automations.manage`.
 */
beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.automations.manage', static fn (): bool => true);

    $this->actingAsUser();
});

// ---------------------------------------------------------------------- index

it('lists automations with the schedule, the agent and the next run', function (): void {
    AutomationFactory::make(['name' => 'Nightly report', 'slug' => 'nightly-report']);

    Livewire::test(AutomationsIndex::class)
        ->assertOk()
        ->assertSee('Nightly report')
        ->assertSee('nightly-report')
        ->assertSee('Cron')
        ->assertSee('0 9 * * *');
});

it('denies the index to a user without pandora.access', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);

    Livewire::test(AutomationsIndex::class)->assertForbidden();
});

it('says an externally-woken automation waits for its trigger rather than showing a gap', function (): void {
    // A blank cell reads as missing data. "Waits for its trigger" is the
    // actual state of a webhook automation, and it is not a problem.
    AutomationFactory::make([
        'slug' => 'inbound',
        'trigger_type' => AutomationTrigger::Webhook->value,
        'cron_expression' => null,
    ]);

    Livewire::test(AutomationsIndex::class)->assertSee('Waits for its trigger');
});

it('warns that nothing has ever fired, because that is usually the scheduler', function (): void {
    // The single most common "automation problem" is that nobody is running
    // `schedule:run`. Somebody debugging a perfectly correct cron expression
    // deserves to be told.
    AutomationFactory::make();

    Livewire::test(AutomationsIndex::class)->assertSee('schedule:run');
});

it('filters by status and by search', function (): void {
    AutomationFactory::make(['name' => 'Nightly report', 'slug' => 'nightly-report']);
    AutomationFactory::make(['name' => 'Weekly digest', 'slug' => 'weekly-digest', 'enabled' => false]);

    Livewire::test(AutomationsIndex::class)
        ->set('statusFilter', 'enabled')
        ->assertSee('Nightly report')
        ->assertDontSee('Weekly digest')
        ->set('statusFilter', '')
        ->set('search', 'digest')
        ->assertSee('Weekly digest')
        ->assertDontSee('Nightly report');
});

// -------------------------------------------------------------------- writing

it('offers no create control without pandora.automations.manage, and refuses a forged create', function (): void {
    Gate::define('pandora.automations.manage', static fn (): bool => false);

    AgentFactory::database(['slug' => 'support']);

    Livewire::test(AutomationsIndex::class)
        ->assertDontSee('New automation')
        ->set('newName', 'Sneaky')
        ->set('newAgent', 'support')
        ->call('create')
        ->assertForbidden();

    expect(Automation::query()->count())->toBe(0);
});

it('creates a disabled one-off automation at observe_only, and audits it', function (): void {
    // An automation that began running the moment it was named would turn a
    // half-finished thought into an incident, at 3am, repeatedly.
    AgentFactory::database(['slug' => 'support', 'autonomy_level' => AutonomyLevel::ActWithinPolicy->value]);

    Livewire::test(AutomationsIndex::class)
        ->call('startCreating')
        ->set('newName', 'Nightly report')
        ->set('newAgent', 'support')
        ->call('create')
        ->assertRedirect();

    /** @var Automation $automation */
    $automation = Automation::query()->firstOrFail();

    expect($automation->enabled)->toBeFalse()
        ->and($automation->trigger_type)->toBe(AutomationTrigger::OneOff)
        ->and($automation->autonomy_level)->toBe(AutonomyLevel::ObserveOnly)
        ->and($automation->next_run_at)->toBeNull()
        ->and(AuditLog::query()->pluck('action')->all())->toContain('automation.created');
});

it('gives a second automation of the same name a distinct slug', function (): void {
    AgentFactory::database(['slug' => 'support']);

    foreach ([1, 2] as $ignored) {
        Livewire::test(AutomationsIndex::class)
            ->set('newName', 'Nightly report')
            ->set('newAgent', 'support')
            ->call('create');
    }

    expect(Automation::query()->pluck('slug')->all())->toBe(['nightly-report', 'nightly-report-2']);
});

it('refuses to create against an agent that has gone', function (): void {
    Livewire::test(AutomationsIndex::class)
        ->set('newName', 'Nightly report')
        ->set('newAgent', 'never-existed')
        ->call('create')
        ->assertSee('no longer exists');

    expect(Automation::query()->count())->toBe(0);
});

// -------------------------------------------------------------------- toggling

it('recomputes the next run when enabling, rather than trusting a stale one', function (): void {
    // The schedule may have been edited while it was off, and an automation
    // that fired on its old schedule after being re-enabled is
    // indistinguishable from Pandora ignoring the edit.
    $automation = AutomationFactory::make(['enabled' => false]);
    $automation->forceFill(['next_run_at' => now()->subYear()])->save();

    Livewire::test(AutomationsIndex::class)->call('toggle', $automation->id);

    expect($automation->refresh()->enabled)->toBeTrue()
        ->and($automation->next_run_at->isFuture())->toBeTrue()
        ->and(AuditLog::query()->pluck('action')->all())->toContain('automation.enabled');
});

it('clears the next run when disabling, and audits it as a warning', function (): void {
    $automation = AutomationFactory::due();

    Livewire::test(AutomationsIndex::class)->call('toggle', $automation->id);

    expect($automation->refresh()->enabled)->toBeFalse()
        ->and($automation->next_run_at)->toBeNull()
        ->and($automation->disabled_at)->not->toBeNull();

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'automation.disabled')->firstOrFail();

    expect($entry->severity)->toBe('warning');
});

it('clears the failure count when an operator re-enables', function (): void {
    // Somebody who has just fixed the cause is not helped by an automation
    // that disables itself again after one more failure.
    $automation = AutomationFactory::make(['enabled' => false]);
    $automation->forceFill(['consecutive_failures' => 4])->save();

    Livewire::test(AutomationsIndex::class)->call('toggle', $automation->id);

    expect($automation->refresh()->consecutive_failures)->toBe(0);
});

it('refuses a forged toggle from a user without the ability', function (): void {
    Gate::define('pandora.automations.manage', static fn (): bool => false);

    $automation = AutomationFactory::make();

    Livewire::test(AutomationsIndex::class)
        ->call('toggle', $automation->id)
        ->assertForbidden();

    expect($automation->refresh()->enabled)->toBeTrue();
});

// ------------------------------------------------------------------ goal queue

it('shows pending proposals to somebody who can decide about them', function (): void {
    pendingProposal();

    Livewire::test(AutomationsIndex::class)
        ->assertSee('Proposed by agents')
        ->assertSee('Weekly reconciliation');
});

it('hides the proposal queue from a reader who cannot act on it', function (): void {
    // A queue of decisions somebody cannot make is not information, it is
    // noise with a button they will be refused for pressing.
    Gate::define('pandora.automations.manage', static fn (): bool => false);

    pendingProposal();

    Livewire::test(AutomationsIndex::class)->assertDontSee('Proposed by agents');
});

it('promotes a proposal into a disabled automation and goes to it', function (): void {
    $observation = pendingProposal();

    Livewire::test(AutomationsIndex::class)
        ->call('promote', $observation->id)
        ->assertRedirect();

    /** @var Automation $automation */
    $automation = Automation::query()->firstOrFail();

    expect($automation->enabled)->toBeFalse()
        ->and($observation->refresh()->status)->toBe(ObservationStatus::Promoted);
});

it('dismisses a proposal', function (): void {
    $observation = pendingProposal();

    Livewire::test(AutomationsIndex::class)
        ->call('dismiss', $observation->id)
        ->assertSee('dismissed');

    expect($observation->refresh()->status)->toBe(ObservationStatus::Dismissed);
});

it('explains rather than duplicating when two operators promote the same proposal', function (): void {
    $observation = pendingProposal();

    Livewire::test(AutomationsIndex::class)->call('promote', $observation->id);

    Livewire::test(AutomationsIndex::class)
        ->call('promote', $observation->id)
        ->assertSee('already decided');

    expect(Automation::query()->count())->toBe(1);
});

// -------------------------------------------------------------------- helpers

function pendingProposal(): Observation
{
    $agent = AgentFactory::database(['slug' => 'proposer-'.substr(uniqid(), -6)]);

    /** @var Observation $observation */
    $observation = Observation::query()->create([
        'agent_id' => $agent->getKey(),
        'title' => 'Weekly reconciliation',
        'proposal' => 'Reconcile last week\'s payouts and report anything unmatched.',
        'status' => ObservationStatus::Pending->value,
    ]);

    return $observation;
}
