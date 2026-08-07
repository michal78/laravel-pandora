<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRegistry;
use Pandora\Audit\AuditLogger;
use Pandora\Automation\Automation;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\MemoryItem;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Runs\Run;
use Pandora\Skills\Skill;
use Pandora\UI\Feature;
use Pandora\UI\PandoraGate;
use Pandora\Usage\UsageRecord;
use Pandora\Workspaces\Workspace;

/**
 * One agent: what it is, what it is told, what it runs on, and how far it may
 * go without asking.
 *
 * Reading needs `pandora.access`; writing needs `pandora.agents.manage`.
 * Instructions are gated separately on `pandora.prompts.view` -- a prompt is
 * the most quietly sensitive thing on this page, and plenty of deployments
 * want an operator who can enable an agent without reading what it was told.
 *
 * ## Class-defined agents
 *
 * `definition_class` is nullable, so this page serves two kinds of agent. For
 * a class-defined one the registry reports which attributes its definition
 * owns, and those render read-only, naming the class. A write to one of them
 * is REFUSED rather than accepted -- accepting it would produce an edit that
 * looks saved until the next deploy silently reverts it.
 */
final class AgentDetail extends Component
{
    public string $slug = '';

    #[Url(as: 'tab', except: 'overview')]
    public string $tab = 'overview';

    public bool $editing = false;

    public ?string $error = null;

    public ?string $saved = null;

    /** Overview */
    public string $name = '';

    public string $description = '';

    public bool $enabled = false;

    /** Instructions */
    public string $systemInstructions = '';

    public string $roleInstructions = '';

    /** Models */
    public string $defaultProvider = '';

    public string $defaultModel = '';

    /** One 'provider:model' per line -- an ordered chain reads better as a list than as JSON. */
    public string $fallbackModels = '';

    /** Limits and autonomy */
    public int $maxIterations = 0;

    public int $maxToolCalls = 0;

    public int $maxDurationSeconds = 0;

    public int $contextBudgetTokens = 0;

    public string $tokenBudget = '';

    public string $costBudget = '';

    public string $currency = 'USD';

    public string $autonomyLevel = '';

    /**
     * Tabs that exist as headings before the work that fills them.
     *
     * Listing them is deliberate. An operator who cannot find where tools are
     * granted should learn that the page is coming, not conclude that agents
     * cannot be granted tools.
     *
     * No release number is quoted to the operator. A date we might miss is
     * worth less to them than knowing the capability exists and where it will
     * live, and a number left unrevised is how a page ends up promising a
     * release that shipped months ago.
     *
     * @var array<string, array{label: string, note: string}>
     */
    public const PENDING_TABS = [
        'tools' => [
            'label' => 'Tools',
            'note' => 'Tool grants are stored on the agent and enforced today; this tab will edit them rather than requiring a class definition or a seeder.',
        ],

        'channels' => ['label' => 'Channels', 'note' => 'Where this agent can be reached, and which identities map to it.'],

        'permissions' => ['label' => 'Permissions', 'note' => 'Delegation, MCP access and the abilities required to run it.'],
    ];

    /**
     * The pending tabs, plus anything built but not yet released.
     *
     * A deferred tab is a promise like any other and says so the same way,
     * rather than presenting itself as working and explaining inside. The
     * const holds what was never built; this adds what is finished and
     * withheld, and the tab returns to the live list when the flag flips.
     *
     * @return array<string, array{label: string, note: string}>
     */
    public static function pendingTabs(): array
    {
        $pending = self::PENDING_TABS;

        if (Feature::disabled('workspaces')) {
            $pending['workspace'] = [
                'label' => 'Workspace',
                'note' => 'A directory this agent may read and write inside, bounded by a root it cannot escape, a quota it cannot exceed and a list of types it cannot widen. Until then it reaches no files, which is what it would do with no workspace attached in any case.',
            ];
        }

        return $pending;
    }

    public function mount(string $agent): void
    {
        PandoraGate::authorize('access');

        $this->slug = $agent;

        $found = $this->agent();

        if ($found === null) {
            abort(404);
        }

        $this->fillFrom($found);
    }

    /**
     * Scoped by the tenant global scope on the model, so a slug from another
     * tenant resolves to nothing and answers 404 -- the same answer a slug
     * that never existed gets, which is the point.
     */
    public function agent(): ?Agent
    {
        /** @var Agent|null $agent */
        $agent = Agent::query()->where('slug', $this->slug)->first();

        return $agent;
    }

    public function selectTab(string $tab): void
    {
        $this->tab = $tab;
        $this->editing = false;
        $this->error = null;
        $this->saved = null;
        $this->resetValidation();

        $agent = $this->agent();

        if ($agent !== null) {
            $this->fillFrom($agent);
        }
    }

    public function startEditing(): void
    {
        PandoraGate::authorize('agents.manage');

        if ($this->tab === 'instructions') {
            PandoraGate::authorize('prompts.view');
        }

        $this->editing = true;
        $this->error = null;
        $this->saved = null;
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->error = null;
        $this->resetValidation();

        $agent = $this->agent();

        if ($agent !== null) {
            $this->fillFrom($agent);
        }
    }

    /**
     * Save the fields belonging to the current tab.
     *
     * Per tab rather than per page: a form that submits every attribute makes
     * the audit entry useless, because every save then looks like a change to
     * everything.
     */
    public function save(AgentRegistry $registry, AuditLogger $audit): void
    {
        PandoraGate::authorize('agents.manage');

        $agent = $this->agent();

        if ($agent === null) {
            abort(404);
        }

        if ($this->tab === 'instructions') {
            PandoraGate::authorize('prompts.view');
        }

        $this->error = null;
        $this->saved = null;

        $this->validate($this->rulesForTab(), attributes: $this->validationAttributes());

        $candidate = $this->candidateAttributes();
        $managed = $registry->managedKeysFor($agent);

        // Compare against what is stored, so an unchanged managed field
        // submitted by the form is not mistaken for an attempt to change it.
        $changes = [];

        foreach ($candidate as $key => $value) {
            if ($this->isUnchanged($agent, $key, $value)) {
                continue;
            }

            $changes[$key] = $value;
        }

        $refused = array_values(array_intersect(array_keys($changes), $managed));

        if ($refused !== []) {
            // Refused, not silently dropped. The definition class is the one
            // place this can be changed, and saying so is more useful than
            // accepting the edit and reverting it at the next deploy.
            $this->error = sprintf(
                '%s %s defined by %s and can only be changed there. Nothing was saved.',
                implode(', ', array_map(self::labelFor(...), $refused)),
                count($refused) === 1 ? 'is' : 'are',
                (string) $agent->definition_class,
            );

            $this->fillFrom($agent);
            $this->editing = false;

            return;
        }

        if ($changes === []) {
            $this->editing = false;
            $this->saved = 'No changes to save.';

            return;
        }

        $before = [];

        foreach (array_keys($changes) as $key) {
            $before[$key] = $this->storedValue($agent, $key);
        }

        $agent->fill($changes)->save();
        $registry->flush();

        $audit->record(
            action: 'agent.updated',
            targetType: 'agent',
            targetId: $agent->id,
            metadata: [
                'slug' => $agent->slug,
                'tab' => $this->tab,
                'changed' => array_keys($changes),
                'before' => $before,
                'after' => $changes,
            ],
        );

        $this->editing = false;
        $this->saved = 'Saved.';
        $this->fillFrom($agent->refresh());
    }

    /**
     * Soft-delete a database-defined agent.
     *
     * A class-defined one cannot be deleted here: the next registry sync would
     * recreate it, so the button would appear to work and then undo itself.
     * Deleting it means deleting the class.
     */
    public function delete(AgentRegistry $registry, AuditLogger $audit): void
    {
        PandoraGate::authorize('agents.manage');

        $agent = $this->agent();

        if ($agent === null) {
            abort(404);
        }

        if ($registry->definitionIsInstalled($agent)) {
            $this->error = sprintf(
                'This agent is defined by %s. Remove the class from the host application to delete it.',
                (string) $agent->definition_class,
            );

            return;
        }

        $audit->record(
            action: 'agent.deleted',
            targetType: 'agent',
            targetId: $agent->id,
            severity: 'warning',
            metadata: ['slug' => $agent->slug, 'name' => $agent->name],
        );

        $agent->delete();
        $registry->flush();

        $this->redirectRoute('pandora.agents', navigate: true);
    }

    public function render(AgentRegistry $registry): View
    {
        $agent = $this->agent();

        if ($agent === null) {
            abort(404);
        }

        $canViewPrompts = PandoraGate::allows('prompts.view');

        return view('pandora::livewire.agent-detail', [
            'agent' => $agent,
            'managedKeys' => $registry->managedKeysFor($agent),
            'definitionInstalled' => $registry->definitionIsInstalled($agent),
            'canManage' => PandoraGate::allows('agents.manage'),
            'canViewPrompts' => $canViewPrompts,
            'canViewCosts' => PandoraGate::allows('costs.view'),
            'autonomyLevels' => AutonomyLevel::cases(),
            'runs' => $this->tab === 'runs' ? $this->recentRuns($agent) : collect(),
            'automations' => $this->tab === 'automations' ? $this->automationsFor($agent) : collect(),
            'canManageAutomations' => PandoraGate::allows('automations.manage'),
            'usage' => $this->tab === 'usage' ? $this->usageSummary($agent) : [],
            'memories' => $this->tab === 'memory' ? $this->memoriesFor($agent) : collect(),
            'skills' => $this->tab === 'skills' ? $this->skillsFor($agent) : collect(),
            'workspace' => $this->tab === 'workspace' ? $this->workspaceFor($agent) : null,
            'canManageMemory' => PandoraGate::allows('memory.manage'),
            'pendingTabs' => self::pendingTabs(),
        ])->layout('pandora::layouts.app', ['title' => $agent->name]);
    }

    /**
     * What this agent in particular has written down.
     *
     * Agent-scoped only. A tab showing everything the agent can RETRIEVE would
     * have to include user-scoped memory belonging to whoever it has spoken
     * to, and an admin page is not a session -- there is no "who is standing
     * here" to bound it. Those live on the Memory page, filtered by scope,
     * behind `pandora.memory.manage`.
     *
     * @return Collection<int, MemoryItem>
     */
    private function memoriesFor(Agent $agent): mixed
    {
        return MemoryItem::query()
            ->where('scope', MemoryScope::Agent->value)
            ->where('scope_id', $agent->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, Skill>
     */
    private function skillsFor(Agent $agent): mixed
    {
        return $agent->skills()->orderBy('name')->get();
    }

    private function workspaceFor(Agent $agent): ?Workspace
    {
        if ($agent->workspace_id === null) {
            return null;
        }

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()->find($agent->workspace_id);

        return $workspace;
    }

    /**
     * @return Collection<int, Run>
     */
    private function recentRuns(Agent $agent): mixed
    {
        return Run::query()
            ->where('agent_id', $agent->id)
            ->latest('created_at')
            ->limit(25)
            ->get();
    }

    /**
     * Everything that starts this agent without a human typing.
     *
     * Read-only here, and deliberately so: an automation is edited on its own
     * page, where the schedule, the condition and the autonomy cap sit
     * together. A second editor for the same row is a second place for the two
     * to disagree.
     *
     * @return Collection<int, Automation>
     */
    private function automationsFor(Agent $agent): mixed
    {
        return Automation::query()
            ->where('agent_id', $agent->getKey())
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, int|null>
     */
    private function usageSummary(Agent $agent): array
    {
        $records = UsageRecord::query()->where('agent_id', $agent->id)->get();

        return [
            'calls' => (int) $records->sum('requests'),
            'input_tokens' => (int) $records->sum('input_tokens'),
            'output_tokens' => (int) $records->sum('output_tokens'),
            'total_tokens' => (int) $records->sum('total_tokens'),
            // Null rather than zero when nothing is priced: an agent whose
            // models are all unpriced has an unknown cost, not a free one.
            'cost_micro' => $records->whereNotNull('cost_micro')->isEmpty()
                ? null
                : (int) $records->whereNotNull('cost_micro')->sum('cost_micro'),
            'unpriced' => $records->whereNull('cost_micro')->count(),
        ];
    }

    private function fillFrom(Agent $agent): void
    {
        $this->name = $agent->name;
        $this->description = $agent->description ?? '';
        $this->enabled = $agent->enabled;

        $this->systemInstructions = $agent->system_instructions ?? '';
        $this->roleInstructions = $agent->role_instructions ?? '';

        $this->defaultProvider = $agent->default_provider ?? '';
        $this->defaultModel = $agent->default_model ?? '';
        $this->fallbackModels = implode("\n", $agent->fallback_models ?? []);

        $this->maxIterations = $agent->max_iterations;
        $this->maxToolCalls = $agent->max_tool_calls;
        $this->maxDurationSeconds = $agent->max_duration_seconds;
        $this->contextBudgetTokens = $agent->context_budget_tokens;
        $this->tokenBudget = $agent->token_budget === null ? '' : (string) $agent->token_budget;
        $this->costBudget = $agent->cost_budget_minor === null ? '' : (string) $agent->cost_budget_minor;
        $this->currency = $agent->currency;
        $this->autonomyLevel = $agent->autonomy_level->value;
    }

    /**
     * The attributes the current tab proposes, already normalised to the
     * shapes the model casts expect.
     *
     * @return array<string, mixed>
     */
    private function candidateAttributes(): array
    {
        return match ($this->tab) {
            'overview' => [
                'name' => trim($this->name),
                'description' => trim($this->description) === '' ? null : trim($this->description),
                'enabled' => $this->enabled,
            ],
            'instructions' => [
                'system_instructions' => trim($this->systemInstructions) === '' ? null : trim($this->systemInstructions),
                'role_instructions' => trim($this->roleInstructions) === '' ? null : trim($this->roleInstructions),
            ],
            'models' => [
                'default_provider' => trim($this->defaultProvider) === '' ? null : trim($this->defaultProvider),
                'default_model' => trim($this->defaultModel) === '' ? null : trim($this->defaultModel),
                'fallback_models' => $this->parsedFallbacks(),
            ],
            'limits' => [
                'max_iterations' => $this->maxIterations,
                'max_tool_calls' => $this->maxToolCalls,
                'max_duration_seconds' => $this->maxDurationSeconds,
                'context_budget_tokens' => $this->contextBudgetTokens,
                'token_budget' => $this->tokenBudget === '' ? null : (int) $this->tokenBudget,
                'cost_budget_minor' => $this->costBudget === '' ? null : (int) $this->costBudget,
                'currency' => strtoupper(trim($this->currency)),
                'autonomy_level' => $this->autonomyLevel,
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function parsedFallbacks(): array
    {
        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R/', $this->fallbackModels) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForTab(): array
    {
        return match ($this->tab) {
            'overview' => [
                'name' => ['required', 'string', 'min:2', 'max:120'],
                'description' => ['nullable', 'string', 'max:500'],
            ],
            'instructions' => [
                'systemInstructions' => ['nullable', 'string', 'max:20000'],
                'roleInstructions' => ['nullable', 'string', 'max:20000'],
            ],
            'models' => [
                'defaultProvider' => ['nullable', 'string', 'max:80'],
                'defaultModel' => ['nullable', 'string', 'max:160'],
                'fallbackModels' => ['nullable', 'string', 'max:2000'],
            ],
            'limits' => [
                // Upper bounds as well as lower. A run that may take a million
                // iterations has no limit at all, and the field exists to stop
                // a loop costing money, not to be set to infinity by accident.
                'maxIterations' => ['required', 'integer', 'min:1', 'max:200'],
                'maxToolCalls' => ['required', 'integer', 'min:0', 'max:1000'],
                'maxDurationSeconds' => ['required', 'integer', 'min:5', 'max:86400'],
                'contextBudgetTokens' => ['required', 'integer', 'min:256', 'max:10000000'],
                'tokenBudget' => ['nullable', 'integer', 'min:0'],
                'costBudget' => ['nullable', 'integer', 'min:0'],
                'currency' => ['required', 'string', 'size:3', 'alpha'],
                'autonomyLevel' => ['required', Rule::in(array_column(AutonomyLevel::cases(), 'value'))],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function validationAttributes(): array
    {
        return [
            'systemInstructions' => 'system instructions',
            'roleInstructions' => 'role instructions',
            'defaultProvider' => 'provider',
            'defaultModel' => 'model',
            'fallbackModels' => 'fallback chain',
            'maxIterations' => 'max iterations',
            'maxToolCalls' => 'max tool calls',
            'maxDurationSeconds' => 'timeout',
            'contextBudgetTokens' => 'context budget',
            'tokenBudget' => 'token budget',
            'costBudget' => 'cost budget',
            'autonomyLevel' => 'autonomy level',
        ];
    }

    private function isUnchanged(Agent $agent, string $key, mixed $value): bool
    {
        return $this->storedValue($agent, $key) === $value;
    }

    private function storedValue(Agent $agent, string $key): mixed
    {
        $stored = $agent->getAttribute($key);

        // The enum cast would otherwise never equal the string the form holds.
        return $stored instanceof AutonomyLevel ? $stored->value : $stored;
    }

    private static function labelFor(string $attribute): string
    {
        return match ($attribute) {
            'name' => 'Name',
            'slug' => 'Slug',
            'description' => 'Description',
            'enabled' => 'Enabled',
            'role_instructions' => 'Role instructions',
            'system_instructions' => 'System instructions',
            'default_provider' => 'Provider',
            'default_model' => 'Model',
            'fallback_models' => 'Fallback chain',
            'provider_options' => 'Provider options',
            'max_iterations' => 'Max iterations',
            'max_tool_calls' => 'Max tool calls',
            'max_duration_seconds' => 'Timeout',
            'context_budget_tokens' => 'Context budget',
            'autonomy_level' => 'Autonomy level',
            'tool_policy' => 'Tool policy',
            default => str($attribute)->replace('_', ' ')->ucfirst()->toString(),
        };
    }
}
