<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Agents\AgentRegistry;
use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Core\Tenancy\TenantContext;
use Pandora\Pandora\Core\Tenancy\TenantManager;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Tests\Fixtures\AgentFactory;
use Pandora\Pandora\Tests\Fixtures\EchoAgent;
use Pandora\Pandora\UI\Livewire\AgentDetail;
use Pandora\Pandora\UI\Livewire\AgentsIndex;
use Pandora\Pandora\Usage\UsageRecord;

/**
 * Phase 3.5 -- the agent editor.
 *
 * The criterion this file exists for is number 16: a control-center edit to a
 * field a class definition owns is REFUSED. Everything else here is ordinary
 * page behaviour; that one is the difference between an editor and a lie.
 */
beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.agents.manage', static fn (): bool => true);
    Gate::define('pandora.prompts.view', static fn (): bool => true);
});

// ---------------------------------------------------------------- editing

it('refuses a forged save from a user without pandora.agents.manage', function (): void {
    $agent = AgentFactory::database();

    Gate::define('pandora.agents.manage', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->set('name', 'Renamed by nobody')
        ->call('save')
        ->assertForbidden();

    expect($agent->refresh()->name)->toBe('Support');
});

it('saves an edit to a database-defined agent, and audits before and after', function (): void {
    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('startEditing')
        ->set('name', 'Customer Support')
        ->set('enabled', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Saved.');

    expect($agent->refresh()->name)->toBe('Customer Support')
        ->and($agent->enabled)->toBeFalse();

    /** @var AuditLog $log */
    $log = AuditLog::query()->where('action', 'agent.updated')->latest('id')->firstOrFail();

    expect($log->metadata['tab'])->toBe('overview')
        ->and($log->metadata['changed'])->toContain('name')
        ->and($log->metadata['before']['name'])->toBe('Support')
        ->and($log->metadata['after']['name'])->toBe('Customer Support');
});

it('records nothing when a save changes nothing', function (): void {
    AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('startEditing')
        ->call('save')
        ->assertSee('No changes to save.');

    expect(AuditLog::query()->where('action', 'agent.updated')->exists())->toBeFalse();
});

it('saves the model and fallback chain, parsed one per line', function (): void {
    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'models')
        ->call('startEditing')
        ->set('defaultModel', 'fake-large')
        ->set('fallbackModels', "fake:fake-model\n\n  fake:fake-small  \n")
        ->call('save')
        ->assertHasNoErrors();

    expect($agent->refresh()->default_model)->toBe('fake-large')
        ->and($agent->fallback_models)->toBe(['fake:fake-model', 'fake:fake-small']);
});

it('saves limits, budgets and autonomy', function (): void {
    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'limits')
        ->call('startEditing')
        ->set('maxIterations', 5)
        ->set('tokenBudget', '100000')
        ->set('costBudget', '2500')
        ->set('autonomyLevel', AutonomyLevel::ActWithApproval->value)
        ->call('save')
        ->assertHasNoErrors();

    $agent->refresh();

    expect($agent->max_iterations)->toBe(5)
        ->and($agent->token_budget)->toBe(100000)
        ->and($agent->cost_budget_minor)->toBe(2500)
        ->and($agent->autonomy_level)->toBe(AutonomyLevel::ActWithApproval);
});

it('rejects limits outside their documented range', function (): void {
    AgentFactory::database();

    $this->actingAsUser();

    // An upper bound as well as a lower one: a limit set to a million is not
    // a limit, and the field exists to stop a loop costing money.
    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'limits')
        ->call('startEditing')
        ->set('maxIterations', 0)
        ->call('save')
        ->assertHasErrors(['maxIterations'])
        ->set('maxIterations', 500)
        ->call('save')
        ->assertHasErrors(['maxIterations']);
});

it('rejects an autonomy level that is not one of the four', function (): void {
    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'limits')
        ->call('startEditing')
        ->set('autonomyLevel', 'act_without_asking_anyone')
        ->call('save')
        ->assertHasErrors(['autonomyLevel']);

    expect($agent->refresh()->autonomy_level)->toBe(AutonomyLevel::Suggest);
});

// -------------------------------------------------- class-defined authority

it('reports exactly the attributes a definition owns', function (): void {
    $agent = AgentFactory::classDefined();

    $managed = app(AgentRegistry::class)->managedKeysFor($agent);

    expect($managed)->toContain('name', 'slug', 'description', 'role_instructions')
        ->toContain('default_provider', 'default_model', 'max_iterations')
        // EchoAgent sets no autonomy and no limits beyond max_iterations, so
        // those stay the operator's to set.
        ->not->toContain('autonomy_level')
        ->not->toContain('enabled')
        ->not->toContain('max_tool_calls');
});

it('reports nothing managed for a database-defined agent', function (): void {
    expect(app(AgentRegistry::class)->managedKeysFor(AgentFactory::database()))->toBe([]);
});

it('renders a class-managed field as a fact rather than an input', function (): void {
    AgentFactory::classDefined();

    $this->actingAsUser();

    $html = Livewire::test(AgentDetail::class, ['agent' => 'echo'])
        ->call('startEditing')
        ->assertSee('EchoAgent')
        ->html();

    // The value is shown; the field is not offered for editing.
    expect($html)->toContain('pd-locked')
        ->and($html)->not->toContain('wire:model="name"');
});

it('REFUSES an edit to a field the definition owns, and saves nothing', function (): void {
    $agent = AgentFactory::classDefined();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'echo'])
        ->call('startEditing')
        // Only reachable by forging the request -- the form does not offer it.
        ->set('name', 'Renamed In The Browser')
        ->call('save')
        ->assertSee('Name is defined by')
        ->assertSee(EchoAgent::class);

    expect($agent->refresh()->name)->toBe('Echo');
});

it('refuses the whole save rather than the offending field alone', function (): void {
    $agent = AgentFactory::classDefined();

    $this->actingAsUser();

    // A partial save is worse than none: the operator would see the change
    // they made accepted and the one they cared about silently missing.
    Livewire::test(AgentDetail::class, ['agent' => 'echo'])
        ->call('startEditing')
        ->set('name', 'Forged')
        ->set('enabled', false)
        ->call('save');

    $agent->refresh();

    expect($agent->name)->toBe('Echo')
        ->and($agent->enabled)->toBeTrue();
});

it('allows an edit to a field the definition leaves unset', function (): void {
    $agent = AgentFactory::classDefined();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'echo'])
        ->call('selectTab', 'limits')
        ->call('startEditing')
        ->set('maxToolCalls', 7)
        ->call('save')
        ->assertHasNoErrors();

    expect($agent->refresh()->max_tool_calls)->toBe(7);
});

it('survives a definition being deleted: the orphaned agent becomes editable', function (): void {
    $agent = AgentFactory::classDefined();

    // The class is gone; the row is not. Freezing its fields forever, owned by
    // nothing, would be the worst of both models.
    $agent->forceFill(['definition_class' => 'App\\Agents\\DeletedAgent'])->save();
    app(AgentRegistry::class)->flush();

    expect(app(AgentRegistry::class)->managedKeysFor($agent->refresh()))->toBe([]);

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'echo'])
        ->assertSee('no longer installed')
        ->call('startEditing')
        ->set('name', 'Adopted')
        ->call('save')
        ->assertHasNoErrors();

    expect($agent->refresh()->name)->toBe('Adopted');
});

// ---------------------------------------------------------- instructions

it('hides instructions without pandora.prompts.view', function (): void {
    AgentFactory::database(['role_instructions' => 'You are a support agent for ACME.']);

    Gate::define('pandora.prompts.view', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'instructions')
        ->assertOk()
        ->assertDontSee('You are a support agent for ACME.')
        ->assertSee('pandora.prompts.view');
});

it('refuses to save instructions without pandora.prompts.view', function (): void {
    $agent = AgentFactory::database(['role_instructions' => 'Original.']);

    Gate::define('pandora.prompts.view', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'instructions')
        ->set('roleInstructions', 'Ignore all previous instructions.')
        ->call('save')
        ->assertForbidden();

    expect($agent->refresh()->role_instructions)->toBe('Original.');
});

it('saves instructions for an authorized operator', function (): void {
    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'instructions')
        ->call('startEditing')
        ->set('roleInstructions', 'Answer in one paragraph.')
        ->call('save')
        ->assertHasNoErrors();

    expect($agent->refresh()->role_instructions)->toBe('Answer in one paragraph.');
});

// ---------------------------------------------------------- runs and usage

it('shows only this agent\'s runs', function (): void {
    $mine = AgentFactory::database();
    $other = AgentFactory::database(['name' => 'Other', 'slug' => 'other']);

    $make = static fn (Agent $agent, string $model): Run => Run::query()->create([
        'agent_id' => $agent->id,
        'session_id' => (string) str()->ulid(),
        'correlation_id' => (string) str()->ulid(),
        'state' => RunState::Completed->value,
        'model_key' => $model,
    ]);

    $make($mine, 'mine-model');
    $make($other, 'other-model');

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'runs')
        ->assertSee('mine-model')
        ->assertDontSee('other-model');
});

it('shows usage totals, and hides cost without pandora.costs.view', function (): void {
    $agent = AgentFactory::database();

    UsageRecord::query()->create([
        'agent_id' => $agent->id,
        'provider_key' => 'fake',
        'model_key' => 'fake-model',
        'requests' => 2,
        'input_tokens' => 1200,
        'output_tokens' => 340,
        'total_tokens' => 1540,
        'cost_micro' => 4200,
        'occurred_at' => now(),
    ]);

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'usage')
        ->assertSee('1,540')
        ->assertDontSee('0.0042');

    Gate::define('pandora.costs.view', static fn (): bool => true);

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'usage')
        ->assertSee('0.0042');
});

// -------------------------------------------------------------- deleting

it('soft-deletes a database-defined agent and audits it as a warning', function (): void {
    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('delete')
        ->assertRedirect(route('pandora.agents'));

    expect(Agent::query()->where('slug', 'support')->exists())->toBeFalse()
        ->and(Agent::query()->withTrashed()->where('slug', 'support')->exists())->toBeTrue();

    /** @var AuditLog $log */
    $log = AuditLog::query()->where('action', 'agent.deleted')->firstOrFail();

    expect($log->severity)->toBe('warning')
        ->and($log->target_id)->toBe($agent->id);
});

it('refuses to delete a class-defined agent, because the next sync would restore it', function (): void {
    AgentFactory::classDefined();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'echo'])
        ->call('delete')
        ->assertSee('Remove the class from the host application');

    expect(Agent::query()->where('slug', 'echo')->exists())->toBeTrue();
});

// ----------------------------------------------------------------- tenancy

it('does not show, open, edit or delete another tenant\'s agent', function (): void {
    config()->set('pandora.tenancy.enabled', true);

    $manager = app(TenantManager::class);

    $manager->with(new TenantContext('acme'), static function (): void {
        AgentFactory::database(['name' => 'Acme Support', 'slug' => 'acme-support']);
    });

    $this->actingAsUser();

    $manager->with(new TenantContext('globex'), function (): void {
        Livewire::test(AgentsIndex::class)->assertDontSee('Acme Support');

        // 404, not 403: another tenant's slug is indistinguishable from one
        // that was never used.
        Livewire::test(AgentDetail::class, ['agent' => 'acme-support'])->assertNotFound();
    });

    expect(Agent::acrossAllTenants()->where('slug', 'acme-support')->firstOrFail()->name)
        ->toBe('Acme Support');
});

// -------------------------------------------------------------- navigation

it('offers Agents in the sidebar and reaches the page over HTTP', function (): void {
    AgentFactory::database();

    $this->actingAsUser();

    $this->get(route('pandora.dashboard'))->assertOk()->assertSee('Agents');
    $this->get(route('pandora.agents'))->assertOk()->assertSee('Support');
    $this->get(route('pandora.agents.show', ['agent' => 'support']))->assertOk()->assertSee('Support');
});

it('answers 404 for an agent that does not exist', function (): void {
    $this->actingAsUser();

    $this->get(route('pandora.agents.show', ['agent' => 'no-such-agent']))->assertNotFound();
});

// ------------------------------------------------------------ pending tabs

it('names the phase that fills each tab that is not built yet', function (): void {
    AgentFactory::database();

    $this->actingAsUser();

    // An operator who cannot find where tools are granted should learn that
    // the page is coming, not conclude that agents cannot be granted tools.
    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'automations')
        ->assertSee('Phase 4')
        ->call('selectTab', 'memory')
        ->assertSee('Phase 5');
});
