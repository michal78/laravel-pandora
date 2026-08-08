<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRegistry;
use Pandora\Audit\AuditLog;
use Pandora\Core\Tenancy\TenantContext;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\McpTool;
use Pandora\Mcp\McpToolApproval;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryItem;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Skills\Skill;
use Pandora\Tests\Fixtures\AgentFactory;
use Pandora\Tests\Fixtures\AutomationFactory;
use Pandora\Tests\Fixtures\EchoAgent;
use Pandora\UI\Livewire\AgentDetail;
use Pandora\UI\Livewire\AgentsIndex;
use Pandora\Usage\UsageRecord;
use Pandora\Workspaces\Workspace;

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

// ---------------------------------------------------------------- overview

/**
 * Found by the Phase 3.5 walkthrough: the slug appeared only as faint text
 * beside the heading, while the ULID -- which nobody types anywhere -- had a
 * label of its own. The slug is the name the console and the routes use, so
 * Overview states it as a labelled fact.
 */
it('states the slug on the overview tab, labelled, not only beside the heading', function (): void {
    AgentFactory::database();

    $this->actingAsUser();

    $html = Livewire::test(AgentDetail::class, ['agent' => 'support'])->html();

    expect($html)->toContain('<span class="pd-label">Slug</span>')
        // Still not editable: the slug is fixed at creation.
        ->and($html)->not->toContain('wire:model="slug"');
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

it('says a tab is not built yet, without quoting a release at anyone', function (): void {
    AgentFactory::database();

    $this->actingAsUser();

    // An operator who cannot find where tools are granted should learn that
    // the page is coming, not conclude that agents cannot be granted tools.
    //
    // Memory, Skills and Workspace were on this list until they were built,
    // and their absence here is the assertion that they are no longer
    // promises.
    //
    // No phase or version number is quoted: a number left unrevised is how a
    // page ends up promising a release that shipped months ago, which is
    // exactly what the Tools tab did.
    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'tools')
        ->assertSee('Tools is not here yet')
        ->assertDontSee('Phase')
        ->call('selectTab', 'channels')
        ->assertSee('Channels is not here yet')
        ->assertDontSee('Phase');

    // 'permissions' was here until Phase 6 built it. A tab that stayed on the
    // pending list after it shipped would be the same lie in the other
    // direction.

    expect(array_keys(AgentDetail::PENDING_TABS))
        ->not->toContain('memory')
        ->and(array_keys(AgentDetail::PENDING_TABS))->not->toContain('skills');
});

/**
 * A deferred tab is a promise, and says so the way the others do.
 *
 * Workspace is built rather than unbuilt, which makes it tempting to leave as a
 * working tab that explains itself inside. That reads as a page that is broken.
 * It joins the pending list instead, and leaves it when the flag flips.
 */
it('lists workspace among the tabs that are not here yet', function (): void {
    config()->set('pandora.features.workspaces', false);

    AgentFactory::database(['slug' => 'support']);

    $this->actingAsUser();

    expect(array_keys(AgentDetail::pendingTabs()))->toContain('workspace');

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'workspace')
        ->assertSee('Workspace is not here yet')
        // Not a workspace page reporting that it is empty.
        ->assertDontSee('Root');
});

it('returns workspace to the live tabs once the feature is enabled', function (): void {
    config()->set('pandora.features.workspaces', true);

    AgentFactory::database(['slug' => 'support']);

    $this->actingAsUser();

    expect(array_keys(AgentDetail::pendingTabs()))->not->toContain('workspace');

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'workspace')
        ->assertSee('can reach no files at all')
        ->assertDontSee('is not here yet');
});

// ------------------------------------------------------------- automations

it('lists what starts this agent on its own', function (): void {
    $agent = AgentFactory::database();

    AutomationFactory::make([], $agent);

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'automations')
        ->assertSee('Nightly report')
        // A link out, not an editor: an automation is edited on its own page,
        // where the schedule, the condition and the autonomy cap sit together.
        ->assertSee('nightly-report')
        ->assertSee(route('pandora.automations.show', ['automation' => 'nightly-report']), false);
});

it('shows the EFFECTIVE autonomy of an automation, not what it asked for', function (): void {
    // An automation asking for more than this agent has gets this agent's
    // level, and showing the request rather than the outcome would misreport
    // what the agent can actually do.
    $agent = AgentFactory::database(['autonomy_level' => 'observe_only']);

    AutomationFactory::make([
        'autonomy_level' => 'act_within_policy',
    ], $agent);

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'automations')
        ->assertSee('Observe only')
        ->assertDontSee('Act within policy');
});

it('says plainly when nothing starts the agent on its own', function (): void {
    AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => 'support'])
        ->call('selectTab', 'automations')
        ->assertSee('Nothing starts this agent on its own');
});

// ------------------------------------------------- Phase 5 tabs

it('shows what this agent has written down, and nothing belonging to a person', function (): void {
    $agent = AgentFactory::database();

    $this->actingAsUser();
    Gate::define('pandora.memory.manage', static fn (): bool => true);

    MemoryItem::query()->create([
        'scope' => MemoryScope::Agent->value,
        'scope_id' => $agent->getKey(),
        'type' => MemoryType::AgentCurated->value,
        'content' => 'deploy notes are filed under the release date',
        'source' => MemorySource::Agent->value,
    ]);

    MemoryItem::query()->create([
        'scope' => MemoryScope::User->value,
        'scope_id' => 'App\\Models\\User#1',
        'type' => MemoryType::UserFact->value,
        'content' => 'they prefer the aisle seat',
        'source' => MemorySource::User->value,
    ]);

    // An admin page has no "who is standing here" to bound personal memory by,
    // so it does not show any. That lives on the Memory page, filtered by
    // scope, behind pandora.memory.manage.
    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'memory')
        ->assertSee('deploy notes are filed under the release date')
        ->assertDontSee('they prefer the aisle seat');
});

it('says an agent with no workspace can reach no files', function (): void {
    config()->set('pandora.features.workspaces', true);

    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'workspace')
        ->assertSee('can reach no files at all');
});

it('shows the workspace an agent has', function (): void {
    config()->set('pandora.features.workspaces', true);

    $agent = AgentFactory::database();

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Scratch',
        'slug' => 'scratch',
        'disk' => 'local',
        'root_path' => sys_get_temp_dir(),
        'quota_bytes' => 4096,
    ]);

    $agent->update(['workspace_id' => $workspace->getKey()]);

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'workspace')
        ->assertSee('Scratch')
        ->assertSee('4,096');
});

it('lists attached skills and flags the tools the agent cannot call', function (): void {
    $agent = AgentFactory::database(['tool_policy' => ['allow' => ['ask_user']]]);

    /** @var Skill $skill */
    $skill = Skill::query()->create([
        'name' => 'Release notes',
        'slug' => 'release-notes',
        'instructions' => 'Summarise merged pull requests since the last tag.',
        'required_tools' => ['ask_user', 'send_notification'],
    ]);

    $agent->attachSkill($skill);

    $this->actingAsUser();

    // Surfaced, never resolved: granting a tool because a skill asked for it
    // would make the skill the authority on what the agent may do.
    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'skills')
        ->assertSee('Release notes')
        ->assertSee('send_notification')
        ->assertSee('cannot call the tools in red');
});

it('says so when an agent has no skills', function (): void {
    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'skills')
        ->assertSee('adds to what this agent knows how to do, and grants it nothing');
});

/**
 * Phase 7, criterion 24 — attaching a workspace to an agent.
 *
 * Until this existed, `agents.workspace_id` was writable only from code: the
 * Workspace tab displayed a workspace and offered no way to choose one. It is
 * its own action rather than a field on `save()`, because every other field on
 * that page tunes how the agent behaves and this one decides whether it can
 * touch files at all.
 */
function workspaceFor(string $slug = 'scratch'): Workspace
{
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'disk' => 'local',
        'root_path' => sys_get_temp_dir(),
    ]);

    return $workspace;
}

it('attaches a workspace to an agent', function (): void {
    config()->set('pandora.features.workspaces', true);

    $agent = AgentFactory::database();
    $workspace = workspaceFor();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'workspace')
        ->set('workspaceId', (string) $workspace->getKey())
        ->call('attachWorkspace')
        ->assertSee('Workspace attached');

    expect($agent->refresh()->workspace_id)->toBe($workspace->getKey());

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'agent.workspace_attached')->firstOrFail();

    expect($entry->metadata['workspace'] ?? null)->toBe('scratch');
});

it('detaches a workspace, leaving the agent able to reach no files', function (): void {
    config()->set('pandora.features.workspaces', true);

    $agent = AgentFactory::database();
    $agent->update(['workspace_id' => workspaceFor()->getKey()]);

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'workspace')
        ->set('workspaceId', '')
        ->call('attachWorkspace')
        ->assertSee('can reach no files at all');

    expect($agent->refresh()->workspace_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'agent.workspace_detached')->count())->toBe(1);
});

it('refuses a workspace id that does not exist', function (): void {
    config()->set('pandora.features.workspaces', true);

    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'workspace')
        ->set('workspaceId', '01JCZZZZZZZZZZZZZZZZZZZZZZ')
        ->call('attachWorkspace')
        ->assertSee('does not exist');

    expect($agent->refresh()->workspace_id)->toBeNull();
});

it('does not attach another tenant\'s workspace', function (): void {
    config()->set('pandora.features.workspaces', true);

    $foreign = inTenant('acme', fn (): Workspace => workspaceFor('acme-only'));

    inTenant('globex', function () use ($foreign): void {
        $agent = AgentFactory::database();

        $this->actingAsUser();

        // Not found rather than forbidden: the id is the only thing the
        // request carries, and confirming it exists is the leak.
        Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
            ->set('workspaceId', (string) $foreign->getKey())
            ->call('attachWorkspace')
            ->assertSee('does not exist');

        expect($agent->refresh()->workspace_id)->toBeNull();
    });
});

it('refuses to attach a workspace without agents.manage', function (): void {
    config()->set('pandora.features.workspaces', true);

    Gate::define('pandora.agents.manage', static fn (): bool => false);

    $agent = AgentFactory::database();
    $workspace = workspaceFor();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->set('workspaceId', (string) $workspace->getKey())
        ->call('attachWorkspace')
        ->assertForbidden();

    expect($agent->refresh()->workspace_id)->toBeNull();
});

it('refuses a forged attach while the workspaces feature is off', function (): void {
    config()->set('pandora.features.workspaces', false);

    // Every ability, and the flag still withholds it: the tab that would have
    // honoured the flag is exactly what a forged Livewire call skips.
    Gate::before(static fn (): bool => true);

    $agent = AgentFactory::database();
    $workspace = workspaceFor();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->set('workspaceId', (string) $workspace->getKey())
        ->call('attachWorkspace')
        ->assertNotFound();

    expect($agent->refresh()->workspace_id)->toBeNull();
});

/**
 * Phase 6 — the Permissions tab, which Phase 3.5 listed as a promise.
 *
 * What it answers is "what can this agent reach beyond its own tools": who it
 * may delegate to, and which remote tools somebody approved for it. Both are
 * capabilities that arrived from somewhere other than this page.
 */
it('shows the permissions tab rather than promising it', function (): void {
    expect(array_keys(AgentDetail::pendingTabs()))->not->toContain('permissions');
});

it('says an agent may delegate to nobody, which is the default', function (): void {
    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'permissions')
        ->assertSee('may delegate to nobody');
});

it('lists the agents it may delegate to', function (): void {
    $agent = AgentFactory::database();
    $agent->update(['delegation_policy' => ['allow' => ['researcher']]]);

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'permissions')
        ->assertSee('researcher');
});

it('says no remote tool is approved until one is', function (): void {
    $agent = AgentFactory::database();

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'permissions')
        // Discovery finds tools; it approves none of them, for anybody.
        ->assertSee('No remote tool is approved');
});

it('lists an approved remote tool, and flags one that changed under it', function (): void {
    $agent = AgentFactory::database();

    /** @var McpServer $server */
    $server = McpServer::query()->create([
        'name' => 'Ledger', 'slug' => 'ledger', 'namespace' => 'ledger',
        'endpoint' => 'https://mcp.example.test/rpc',
    ]);

    /** @var McpTool $tool */
    $tool = McpTool::query()->create([
        'server_id' => $server->getKey(),
        'remote_name' => 'lookup_invoice',
        'namespaced_name' => 'ledger.lookup_invoice',
        'description' => 'Look up an invoice.',
        'schema_hash' => str_repeat('a', 64),
    ]);

    McpToolApproval::query()->create([
        'agent_id' => $agent->getKey(),
        'mcp_tool_id' => $tool->getKey(),
        'approved_schema_hash' => str_repeat('a', 64),
        'approved_at' => now(),
    ]);

    $this->actingAsUser();

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'permissions')
        ->assertSee('ledger.lookup_invoice')
        ->assertSee('approved');

    // The server moves under the approval.
    $tool->update(['schema_hash' => str_repeat('b', 64)]);

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])
        ->call('selectTab', 'permissions')
        ->assertSee('changed since approval');
});
