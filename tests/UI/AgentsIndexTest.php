<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRegistry;
use Pandora\Audit\AuditLog;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\AgentFactory;
use Pandora\Tests\Fixtures\EchoAgent;
use Pandora\UI\Livewire\AgentsIndex;

/**
 * Phase 3.5 -- the agent roster, and creating one.
 *
 * Two levels, like the Tools and Providers pages: `pandora.access` reads the
 * roster, because somebody looking at a run wants to know about the thing that
 * produced it. Creating needs `pandora.agents.manage`, because an agent row
 * decides which tools a language model can reach.
 */
beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.agents.manage', static fn (): bool => true);
    Gate::define('pandora.prompts.view', static fn (): bool => true);
});

// ---------------------------------------------------------------- index

it('lists agents with their source, model, autonomy and status', function (): void {
    AgentFactory::database();
    AgentFactory::classDefined();

    $this->actingAsUser();

    Livewire::test(AgentsIndex::class)
        ->assertOk()
        ->assertSee('Support')
        ->assertSee('support')
        ->assertSee('fake:fake-model')
        ->assertSee('Suggest actions')
        ->assertSee('Echo')
        ->assertSee('Class')
        ->assertSee('EchoAgent')
        ->assertSee('Database');
});

it('denies the index to a user without pandora.access', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(AgentsIndex::class)->assertForbidden();
});

it('shows a class definition deployed since the last visit, without a manual sync', function (): void {
    // The registry syncs on read. Without that, a deploy would add an agent
    // that the page kept insisting did not exist.
    app(AgentRegistry::class)->define(EchoAgent::class);

    expect(Agent::query()->where('slug', 'echo')->exists())->toBeFalse();

    $this->actingAsUser();

    Livewire::test(AgentsIndex::class)->assertSee('Echo');

    expect(Agent::query()->where('slug', 'echo')->exists())->toBeTrue();
});

it('filters by source and by search term', function (): void {
    AgentFactory::database();
    AgentFactory::classDefined();

    $this->actingAsUser();

    Livewire::test(AgentsIndex::class)
        ->set('sourceFilter', 'class')
        ->assertSee('Echo')
        ->assertDontSee('Answers customer questions.')
        ->set('sourceFilter', 'database')
        ->assertSee('Support')
        ->assertDontSee('>Echo<', false)
        ->set('sourceFilter', '')
        ->set('search', 'echo')
        ->assertSee('Echo')
        ->assertDontSee('Answers customer questions.');
});

it('counts the runs each agent has produced', function (): void {
    $agent = AgentFactory::database();

    for ($i = 0; $i < 3; $i++) {
        Run::query()->create([
            'agent_id' => $agent->id,
            'session_id' => (string) str()->ulid(),
            'correlation_id' => (string) str()->ulid(),
            'state' => RunState::Completed->value,
        ]);
    }

    $this->actingAsUser();

    Livewire::test(AgentsIndex::class)->assertSee('3');
});

// ------------------------------------------------------------ authorization

// `pandora.agents.manage` is proven denied-by-default in `UI/NavigationTest`,
// which has no gate overrides -- this file defines it permissively in order to
// exercise the editor at all.

it('offers no create control without pandora.agents.manage', function (): void {
    Gate::define('pandora.agents.manage', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(AgentsIndex::class)
        ->assertOk()
        ->assertDontSee('New agent')
        ->assertSee('pandora.agents.manage');
});

it('refuses a forged create from a user without pandora.agents.manage', function (): void {
    // The absent button is presentation. This is the boundary.
    Gate::define('pandora.agents.manage', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(AgentsIndex::class)
        ->set('newName', 'Smuggled')
        ->call('create')
        ->assertForbidden();

    expect(Agent::query()->where('name', 'Smuggled')->exists())->toBeFalse();
});

// --------------------------------------------------------------- creating

it('creates a disabled, observe-only, database-defined agent and audits it', function (): void {
    $this->actingAsUser();

    Livewire::test(AgentsIndex::class)
        ->call('startCreating')
        ->set('newName', 'Billing Helper')
        ->set('newDescription', 'Answers invoice questions.')
        ->call('create')
        ->assertRedirect(route('pandora.agents.show', ['agent' => 'billing-helper']));

    $agent = Agent::query()->where('slug', 'billing-helper')->firstOrFail();

    expect($agent->name)->toBe('Billing Helper')
        ->and($agent->definition_class)->toBeNull()
        // A new agent that could act the moment it was named would turn a
        // typo into an incident.
        ->and($agent->enabled)->toBeFalse()
        ->and($agent->autonomy_level)->toBe(AutonomyLevel::ObserveOnly)
        ->and($agent->allowedTools())->toBe([]);

    expect(AuditLog::query()->where('action', 'agent.created')->where('target_id', $agent->id)->exists())
        ->toBeTrue();
});

it('gives a second agent of the same name a distinct slug', function (): void {
    AgentFactory::database(['name' => 'Support', 'slug' => 'support']);

    $this->actingAsUser();

    Livewire::test(AgentsIndex::class)->set('newName', 'Support')->call('create');

    expect(Agent::query()->where('slug', 'support-2')->exists())->toBeTrue();
});

it('rejects a name that is too short to be an agent', function (): void {
    $this->actingAsUser();

    Livewire::test(AgentsIndex::class)
        ->set('newName', 'x')
        ->call('create')
        ->assertHasErrors(['newName']);
});
