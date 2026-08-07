<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Audit\AuditLog;
use Pandora\Automation\Automation;
use Pandora\Automation\AutomationRun;
use Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Automation\Enums\OccurrenceStatus;
use Pandora\Automation\Webhooks\WebhookSignature;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\AgentFactory;
use Pandora\Tests\Fixtures\AutomationFactory;
use Pandora\UI\Livewire\AutomationDetail;

/**
 * Phase 4 -- the automation editor.
 *
 * Two refusals are what this file exists to pin down. A schedule that cannot
 * be computed is rejected at save, because storing an unparseable cron
 * expression produces an automation that simply never runs with nothing in any
 * log to notice. And the autonomy select is capped at the agent's own level,
 * because offering a value that will be silently clamped teaches an operator
 * that the field is decorative.
 */
beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.automations.manage', static fn (): bool => true);
    Gate::define('pandora.prompts.view', static fn (): bool => true);

    $this->actingAsUser();
});

// --------------------------------------------------------------------- access

it('shows the schedule, the agent and the autonomy ceiling before any tab', function (): void {
    $agent = AgentFactory::database(['name' => 'Reporter', 'slug' => 'reporter']);
    $automation = AutomationFactory::due(['name' => 'Nightly report'], $agent);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->assertOk()
        ->assertSee('Nightly report')
        ->assertSee('Reporter')
        ->assertSee('Autonomy ceiling');
});

it('answers 404 for a slug that does not exist', function (): void {
    Livewire::test(AutomationDetail::class, ['automation' => 'never-existed'])->assertNotFound();
});

it('answers 404 for another tenant\'s automation, not 403', function (): void {
    // Indistinguishable from a slug that was never used, which is the point.
    inTenant('acme', fn (): Automation => AutomationFactory::make(['slug' => 'acme-report']));

    inTenant('globex', function (): void {
        Livewire::test(AutomationDetail::class, ['automation' => 'acme-report'])->assertNotFound();
    });
});

it('says so plainly when the agent has gone', function (): void {
    $automation = AutomationFactory::make();
    $automation->agent->forceDelete();

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->assertSee('no longer exists');
});

// -------------------------------------------------------------------- editing

it('saves an overview edit and audits the tab, the keys and both values', function (): void {
    $automation = AutomationFactory::make();

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('startEditing')
        ->set('name', 'Nightly summary')
        ->call('save')
        ->assertSee('Saved');

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'automation.updated')->firstOrFail();

    expect($automation->refresh()->name)->toBe('Nightly summary')
        ->and($entry->metadata['tab'])->toBe('overview')
        ->and($entry->metadata['changed'])->toBe(['name'])
        ->and($entry->metadata['before']['name'])->toBe('Nightly report')
        ->and($entry->metadata['after']['name'])->toBe('Nightly summary');
});

it('writes no audit entry for a save that changes nothing', function (): void {
    $automation = AutomationFactory::make();

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('startEditing')
        ->call('save')
        ->assertSee('No changes');

    expect(AuditLog::query()->where('action', 'automation.updated')->count())->toBe(0);
});

it('refuses a forged save from a user without pandora.automations.manage', function (): void {
    Gate::define('pandora.automations.manage', static fn (): bool => false);

    $automation = AutomationFactory::make();

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->set('name', 'Hijacked')
        ->call('save')
        ->assertForbidden();

    expect($automation->refresh()->name)->toBe('Nightly report');
});

it('hides the prompt from a user without pandora.prompts.view', function (): void {
    // Same rule as the agent's instructions: a prompt is the most quietly
    // sensitive thing on the page.
    Gate::define('pandora.prompts.view', static fn (): bool => false);

    $automation = AutomationFactory::make(['prompt' => 'The secret question.']);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->assertDontSee('The secret question.')
        ->assertSee('pandora.prompts.view');
});

// ------------------------------------------------------------------- schedule

it('refuses a cron expression it cannot compute, rather than storing one that never fires', function (): void {
    $automation = AutomationFactory::make();

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'schedule')
        ->call('startEditing')
        ->set('cronExpression', 'every tuesday-ish')
        ->call('save')
        ->assertSee('not a valid cron expression');

    expect($automation->refresh()->cron_expression)->toBe('0 9 * * *');
});

it('recomputes the next run when the schedule changes', function (): void {
    // Otherwise it keeps firing on the old cron until the next occurrence,
    // which is reported as Pandora having ignored the edit.
    $automation = AutomationFactory::due();
    $before = $automation->next_run_at;

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'schedule')
        ->call('startEditing')
        ->set('cronExpression', '*/5 * * * *')
        ->call('save');

    expect($automation->refresh()->next_run_at)->not->toEqual($before)
        ->and($automation->next_run_at->isFuture())->toBeTrue();
});

it('interprets a one-off time in the automation\'s own timezone', function (): void {
    // The field says "when this runs" and the operator means it in the zone
    // shown next to it, not the server's.
    $automation = AutomationFactory::make([
        'trigger_type' => AutomationTrigger::OneOff->value,
        'cron_expression' => null,
    ]);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'schedule')
        ->call('startEditing')
        ->set('timezone', 'Europe/Copenhagen')
        ->set('runAt', '2026-12-01T09:00')
        ->call('save');

    expect($automation->refresh()->run_at->utc()->format('H:i'))->toBe('08:00')
        ->and($automation->run_at->setTimezone('Europe/Copenhagen')->format('H:i'))->toBe('09:00');
});

it('refuses an interval shorter than the scheduler can honour', function (): void {
    $automation = AutomationFactory::make();

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'schedule')
        ->call('startEditing')
        ->set('intervalSeconds', '5')
        ->call('save')
        ->assertHasErrors('intervalSeconds');
});

// ------------------------------------------------------------------ behaviour

it('offers only autonomy levels the agent actually has', function (): void {
    // Offering one that will be silently clamped teaches an operator that the
    // field does not mean what it says.
    $agent = AgentFactory::database(['slug' => 'cautious', 'autonomy_level' => AutonomyLevel::Suggest->value]);
    $automation = AutomationFactory::make([], $agent);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'behaviour')
        ->call('startEditing')
        ->assertSee('Observe only')
        ->assertSee('Suggest actions')
        ->assertDontSee('Act within policy')
        ->assertSee('Capped at the agent');
});

it('saves the autonomy budget and the failure limit', function (): void {
    $agent = AgentFactory::database(['slug' => 'doer', 'autonomy_level' => AutonomyLevel::ActWithinPolicy->value]);
    $automation = AutomationFactory::make([], $agent);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'behaviour')
        ->call('startEditing')
        ->set('autonomyBudgetRuns', '6')
        ->set('disableAfterFailures', '2')
        ->call('save');

    expect($automation->refresh()->autonomy_budget_runs)->toBe(6)
        ->and($automation->failureLimit())->toBe(2);
});

it('keeps condition arguments the editor cannot show', function (): void {
    // The arguments are shaped by whatever the host's condition expects, and a
    // JSON textarea is a worse editor than none -- but dropping them on an
    // unrelated save would be worse still.
    config()->set('pandora.automation.conditions', ['over_threshold' => static fn (array $a): bool => true]);

    $automation = AutomationFactory::make([
        'condition' => ['name' => 'over_threshold', 'arguments' => ['threshold' => 5]],
    ]);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'behaviour')
        ->call('startEditing')
        ->set('misfirePolicy', 'run_once')
        ->call('save');

    expect($automation->refresh()->condition)->toBe([
        'name' => 'over_threshold',
        'arguments' => ['threshold' => 5],
    ]);
});

// -------------------------------------------------------------------- run now

it('runs an automation on demand, still clamped to the agent', function (): void {
    $agent = AgentFactory::database(['slug' => 'cautious', 'autonomy_level' => AutonomyLevel::Suggest->value]);
    $automation = AutomationFactory::make(['autonomy_level' => AutonomyLevel::ActWithinPolicy->value], $agent);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('runNow')
        ->assertSee('Started');

    /** @var Run $run */
    $run = Run::query()->firstOrFail();

    // Pressing Run by hand is a decision about timing. It is not permission
    // for the agent to exceed its level.
    expect($run->autonomy_level)->toBe(AutonomyLevel::Suggest);
});

it('reports a refusal from Run now rather than reading as a success', function (): void {
    $automation = AutomationFactory::make();
    $automation->agent->forceFill(['enabled' => false])->save();

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('runNow')
        ->assertSee('No run was created')
        ->assertDontSee('Started.');
});

it('refuses Run now without the ability', function (): void {
    Gate::define('pandora.automations.manage', static fn (): bool => false);

    $automation = AutomationFactory::make();

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('runNow')
        ->assertForbidden();

    expect(Run::query()->count())->toBe(0);
});

// -------------------------------------------------------------------- history

it('shows every occurrence, including the ones that produced no run', function (): void {
    $automation = AutomationFactory::make();

    AutomationRun::query()->create([
        'automation_id' => $automation->getKey(),
        'scheduled_for' => now()->subHour(),
        'status' => OccurrenceStatus::Skipped->value,
        'reason' => 'condition',
        'error' => 'Condition [anything_to_do] evaluated false.',
        'idempotency_key' => 'key-1',
    ]);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'history')
        ->assertSee('Skipped')
        ->assertSee('evaluated false');
});

// -------------------------------------------------------------------- webhook

it('offers a webhook tab only for a webhook automation', function (): void {
    $scheduled = AutomationFactory::make(['slug' => 'scheduled-one']);

    Livewire::test(AutomationDetail::class, ['automation' => $scheduled->slug])
        ->assertDontSee('Signature header');

    $inbound = AutomationFactory::make([
        'slug' => 'inbound',
        'trigger_type' => AutomationTrigger::Webhook->value,
        'cron_expression' => null,
    ]);

    Livewire::test(AutomationDetail::class, ['automation' => $inbound->slug])
        ->call('selectTab', 'webhook')
        ->assertSee('Signature header')
        ->assertSee('/pandora/webhooks/inbound');
});

it('shows a new secret exactly once, and never stores it in a log', function (): void {
    $automation = AutomationFactory::make([
        'slug' => 'inbound',
        'trigger_type' => AutomationTrigger::Webhook->value,
        'cron_expression' => null,
    ]);

    $component = Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'webhook')
        ->call('rotateSecret')
        ->assertSee('not shown again');

    $secret = $component->get('revealedSecret');

    expect($secret)->toStartWith('whsec_')
        // It works, which is the only proof that matters.
        ->and($automation->refresh()->webhook_secret)->toBe($secret);

    // And it is nowhere in the audit trail, which is a place a great many
    // people can read.
    foreach (AuditLog::query()->get() as $entry) {
        expect(json_encode($entry->metadata))->not->toContain($secret);
    }

    // Re-rendering does not show it again.
    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('selectTab', 'webhook')
        ->assertDontSee($secret);
});

it('makes a rotated secret immediately usable, and the old one immediately not', function (): void {
    $automation = AutomationFactory::make([
        'slug' => 'inbound',
        'trigger_type' => AutomationTrigger::Webhook->value,
        'cron_expression' => null,
        'webhook_secret' => 'the-old-secret',
    ]);

    $secret = Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('rotateSecret')
        ->get('revealedSecret');

    $body = json_encode(['x' => 1]);

    $this->call('POST', '/pandora/webhooks/inbound', content: $body, server: [
        'HTTP_X_PANDORA_SIGNATURE' => WebhookSignature::sign('the-old-secret', $body),
    ])->assertStatus(401);

    $this->call('POST', '/pandora/webhooks/inbound', content: $body, server: [
        'HTTP_X_PANDORA_SIGNATURE' => WebhookSignature::sign((string) $secret, $body),
    ])->assertStatus(202);
});

// --------------------------------------------------------------------- delete

it('deletes an automation, keeping its history, at warning severity', function (): void {
    $automation = AutomationFactory::make();

    AutomationRun::query()->create([
        'automation_id' => $automation->getKey(),
        'scheduled_for' => now(),
        'status' => OccurrenceStatus::Dispatched->value,
        'idempotency_key' => 'key-1',
    ]);

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('delete')
        ->assertRedirect();

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'automation.deleted')->firstOrFail();

    expect(Automation::query()->count())->toBe(0)
        ->and(Automation::query()->withTrashed()->count())->toBe(1)
        ->and(AutomationRun::query()->count())->toBe(1)
        ->and($entry->severity)->toBe('warning');
});

it('refuses a forged delete', function (): void {
    Gate::define('pandora.automations.manage', static fn (): bool => false);

    $automation = AutomationFactory::make();

    Livewire::test(AutomationDetail::class, ['automation' => $automation->slug])
        ->call('delete')
        ->assertForbidden();

    expect(Automation::query()->count())->toBe(1);
});
