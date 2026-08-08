{{--
    One agent, across the tabs that exist.

    The single idea this view is organised around: a field a class definition
    owns is rendered as a fact, not as a disabled input. `pd-locked` states the
    value and names the class that decides it, so an operator learns where to
    change it instead of learning that the form is broken.
--}}
@php
    /** A field the definition owns — read-only here, whatever the abilities say. */
    $locked = fn (string $key): bool => in_array($key, $managedKeys, true);
    $editable = $canManage && $editing;
@endphp

<div class="pd-stack">
    <div class="pd-row">
        <div>
            <h2 class="pd-card-title" style="margin: 0">{{ $agent->name }}</h2>
            <span class="pd-mono pd-faint">{{ $agent->slug }}</span>
        </div>

        <div class="pd-row pd-row-end">
            @if ($agent->enabled)
                <x-pandora::badge tone="success">Enabled</x-pandora::badge>
            @else
                <x-pandora::badge tone="muted">Disabled</x-pandora::badge>
            @endif

            @if ($agent->isClassDefined() && $definitionInstalled)
                <x-pandora::badge tone="info">Class-defined</x-pandora::badge>
            @elseif ($agent->isClassDefined())
                <x-pandora::badge tone="warning">Orphaned definition</x-pandora::badge>
            @else
                <x-pandora::badge tone="muted">Database-defined</x-pandora::badge>
            @endif

            <a class="pd-btn pd-btn-ghost" href="{{ route('pandora.agents') }}" wire:navigate>All agents</a>
        </div>
    </div>

    @if ($agent->isClassDefined() && $definitionInstalled)
        <div class="pd-notice pd-notice-info">
            Defined by <span class="pd-mono">{{ $agent->definition_class }}</span>. The fields that class
            sets are authoritative and are shown here rather than edited — change them in the class, and
            they update on the next deploy. Fields it leaves unset stay editable here.
        </div>
    @elseif ($agent->isClassDefined())
        <div class="pd-notice pd-notice-warning">
            This agent was created by <span class="pd-mono">{{ $agent->definition_class }}</span>, which is
            no longer installed. Nothing is authoritative over it any more, so every field is editable —
            and re-installing the class will take its fields back.
        </div>
    @endif

    @if ($error !== null)
        <div class="pd-notice pd-notice-danger">{{ $error }}</div>
    @endif

    @if ($saved !== null)
        <div class="pd-notice pd-notice-success">{{ $saved }}</div>
    @endif

    <div class="pd-tabs" role="tablist">
        @php
            $liveTabs = [
                'overview' => 'Overview',
                'instructions' => 'Instructions',
                'models' => 'Models',
                'limits' => 'Limits & Autonomy',
                'automations' => 'Automations',
                'skills' => 'Skills',
                'memory' => 'Memory',
                'workspace' => 'Workspace',
                'runs' => 'Runs',
                'usage' => 'Usage',
            ];

            // Held back, and so a promise rather than a page: it moves to the
            // pending list below and is rendered there.
            if (isset($pendingTabs['workspace'])) {
                unset($liveTabs['workspace']);
            }
        @endphp

        @foreach ($liveTabs as $key => $label)
            <button type="button" role="tab" wire:key="tab-{{ $key }}"
                    class="pd-tab {{ $tab === $key ? 'is-active' : '' }}"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    wire:click="selectTab('{{ $key }}')">{{ $label }}</button>
        @endforeach

        @foreach ($pendingTabs as $key => $pending)
            <button type="button" role="tab" wire:key="tab-{{ $key }}"
                    class="pd-tab pd-tab-pending {{ $tab === $key ? 'is-active' : '' }}"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    wire:click="selectTab('{{ $key }}')">{{ $pending['label'] }}</button>
        @endforeach
    </div>

    {{-- ------------------------------------------------------------ overview --}}
    @if ($tab === 'overview')
        <x-pandora::card title="Overview">
            <x-slot:actions>
                @if ($canManage && ! $editing)
                    <button type="button" class="pd-btn pd-btn-sm" wire:click="startEditing">Edit</button>
                @endif
            </x-slot:actions>

            <form wire:submit="save" class="pd-stack">
                <div class="pd-field">
                    <label class="pd-label" for="pd-name">Name</label>
                    @if ($locked('name'))
                        <div class="pd-locked"><span class="pd-locked-mark" aria-hidden="true">◆</span>{{ $agent->name }}</div>
                    @elseif ($editable)
                        <input id="pd-name" type="text" class="pd-input" wire:model="name">
                        @error('name') <p class="pd-error">{{ $message }}</p> @enderror
                    @else
                        <p>{{ $agent->name }}</p>
                    @endif
                </div>

                <div class="pd-field">
                    <label class="pd-label" for="pd-description">Description</label>
                    @if ($locked('description'))
                        <div class="pd-locked"><span class="pd-locked-mark" aria-hidden="true">◆</span>{{ $agent->description ?? '—' }}</div>
                    @elseif ($editable)
                        <textarea id="pd-description" class="pd-textarea" rows="2" wire:model="description"></textarea>
                        @error('description') <p class="pd-error">{{ $message }}</p> @enderror
                    @else
                        <p class="pd-muted">{{ $agent->description ?? '—' }}</p>
                    @endif
                </div>

                <div class="pd-field">
                    <label class="pd-label" for="pd-enabled">Enabled</label>
                    @if ($locked('enabled'))
                        <div class="pd-locked">
                            <span class="pd-locked-mark" aria-hidden="true">◆</span>{{ $agent->enabled ? 'Yes' : 'No' }}
                        </div>
                    @elseif ($editable)
                        <label class="pd-row">
                            <input id="pd-enabled" type="checkbox" wire:model="enabled">
                            <span class="pd-muted">A disabled agent cannot be started, by anyone or anything.</span>
                        </label>
                    @else
                        <p class="pd-muted">{{ $agent->enabled ? 'Yes' : 'No' }}</p>
                    @endif
                </div>

                <div class="pd-grid pd-grid-split">
                    {{--
                        The slug is the name an operator actually types -- at the
                        console, in a route, in a config. It is fixed at creation
                        and so is stated rather than edited, but stating it only
                        in faint text beside the heading left it easy to miss on
                        the one tab that claims to hold the agent's identity.
                    --}}
                    <div class="pd-field">
                        <span class="pd-label">Slug</span>
                        <p class="pd-mono">{{ $agent->slug }}</p>
                    </div>
                    <div class="pd-field">
                        <span class="pd-label">Identifier</span>
                        <p class="pd-mono pd-faint">{{ $agent->id }}</p>
                    </div>
                    <div class="pd-field">
                        <span class="pd-label">Created</span>
                        <p class="pd-muted">{{ $agent->created_at?->toDayDateTimeString() ?? '—' }}</p>
                    </div>
                </div>

                @include('pandora::livewire.partials.agent-form-actions')
            </form>
        </x-pandora::card>

        @if ($canManage && ! $agent->isClassDefined())
            <x-pandora::card title="Delete">
                <p class="pd-muted">
                    Deleting is reversible in the database — the row is soft-deleted, and its runs,
                    conversations and audit history are untouched. The agent stops being startable.
                </p>
                <div class="pd-row" style="margin-top: var(--pd-space-3)">
                    <button type="button" class="pd-btn pd-btn-danger"
                            wire:click="delete"
                            wire:confirm="Delete {{ $agent->name }}? Its history is kept, but it can no longer be started.">
                        Delete agent
                    </button>
                </div>
            </x-pandora::card>
        @endif
    @endif

    {{-- -------------------------------------------------------- instructions --}}
    @if ($tab === 'instructions')
        @unless ($canViewPrompts)
            <x-pandora::card title="Instructions">
                <x-pandora::empty-state title="Instructions are hidden" :mark="false">
                    Reading what an agent has been told needs the
                    <span class="pd-mono">pandora.prompts.view</span> ability. Everything else about this
                    agent is visible without it.
                </x-pandora::empty-state>
            </x-pandora::card>
        @else
            <x-pandora::card title="Instructions">
                <x-slot:actions>
                    @if ($canManage && ! $editing)
                        <button type="button" class="pd-btn pd-btn-sm" wire:click="startEditing">Edit</button>
                    @endif
                </x-slot:actions>

                <form wire:submit="save" class="pd-stack">
                    <div class="pd-field">
                        <label class="pd-label" for="pd-system-instructions">System instructions</label>
                        <p class="pd-help">
                            The framework's own boundary, sent before the persona. A class definition
                            cannot set this by design — <span class="pd-mono">AgentBlueprint</span> exposes
                            no method for it.
                        </p>
                        @if ($editable)
                            <textarea id="pd-system-instructions" class="pd-textarea" rows="6"
                                      wire:model="systemInstructions"></textarea>
                            @error('systemInstructions') <p class="pd-error">{{ $message }}</p> @enderror
                        @else
                            <pre class="pd-code">{{ $agent->system_instructions ?? '—' }}</pre>
                        @endif
                    </div>

                    <div class="pd-field">
                        <label class="pd-label" for="pd-role-instructions">Role instructions</label>
                        <p class="pd-help">The persona and the task. This is what a definition's
                            <span class="pd-mono">instructions()</span> sets.</p>

                        @if ($locked('role_instructions'))
                            <pre class="pd-code">{{ $agent->role_instructions ?? '—' }}</pre>
                            <p class="pd-help">◆ Defined by <span class="pd-mono">{{ $agent->definition_class }}</span>.</p>
                        @elseif ($editable)
                            <textarea id="pd-role-instructions" class="pd-textarea" rows="10"
                                      wire:model="roleInstructions"></textarea>
                            @error('roleInstructions') <p class="pd-error">{{ $message }}</p> @enderror
                        @else
                            <pre class="pd-code">{{ $agent->role_instructions ?? '—' }}</pre>
                        @endif
                    </div>

                    @include('pandora::livewire.partials.agent-form-actions')
                </form>
            </x-pandora::card>
        @endunless
    @endif

    {{-- -------------------------------------------------------------- models --}}
    @if ($tab === 'models')
        <x-pandora::card title="Models">
            <x-slot:actions>
                @if ($canManage && ! $editing)
                    <button type="button" class="pd-btn pd-btn-sm" wire:click="startEditing">Edit</button>
                @endif
            </x-slot:actions>

            <form wire:submit="save" class="pd-stack">
                <div class="pd-grid pd-grid-split">
                    <div class="pd-field">
                        <label class="pd-label" for="pd-provider">Provider</label>
                        @if ($locked('default_provider'))
                            <div class="pd-locked"><span class="pd-locked-mark" aria-hidden="true">◆</span>
                                <span class="pd-mono">{{ $agent->default_provider ?? '—' }}</span></div>
                        @elseif ($editable)
                            <input id="pd-provider" type="text" class="pd-input" wire:model="defaultProvider"
                                   placeholder="deployment default">
                            @error('defaultProvider') <p class="pd-error">{{ $message }}</p> @enderror
                        @else
                            <p class="pd-mono">{{ $agent->default_provider ?? 'deployment default' }}</p>
                        @endif
                    </div>

                    <div class="pd-field">
                        <label class="pd-label" for="pd-model">Model</label>
                        @if ($locked('default_model'))
                            <div class="pd-locked"><span class="pd-locked-mark" aria-hidden="true">◆</span>
                                <span class="pd-mono">{{ $agent->default_model ?? '—' }}</span></div>
                        @elseif ($editable)
                            <input id="pd-model" type="text" class="pd-input" wire:model="defaultModel"
                                   placeholder="deployment default">
                            @error('defaultModel') <p class="pd-error">{{ $message }}</p> @enderror
                        @else
                            <p class="pd-mono">{{ $agent->default_model ?? 'deployment default' }}</p>
                        @endif
                    </div>
                </div>

                <div class="pd-field">
                    <label class="pd-label" for="pd-fallbacks">Fallback chain</label>
                    <p class="pd-help">
                        One <span class="pd-mono">provider:model</span> per line, tried in order when the
                        one above is unavailable, rate-limited, or too small for the context. Routing is
                        deterministic (ADR-0006) — this list is read top to bottom, never optimised.
                    </p>

                    @if ($locked('fallback_models'))
                        <div class="pd-locked"><span class="pd-locked-mark" aria-hidden="true">◆</span>
                            <span class="pd-mono">{{ implode(' → ', $agent->fallback_models ?? []) ?: '—' }}</span></div>
                    @elseif ($editable)
                        <textarea id="pd-fallbacks" class="pd-textarea pd-mono" rows="4"
                                  wire:model="fallbackModels"></textarea>
                        @error('fallbackModels') <p class="pd-error">{{ $message }}</p> @enderror
                    @else
                        <p class="pd-mono">{{ implode(' → ', $agent->fallback_models ?? []) ?: '—' }}</p>
                    @endif
                </div>

                <p class="pd-help">
                    Names are not validated against the catalog here — a model can be configured before it
                    is synced, and the Providers page is where the catalog is inspected.
                </p>

                @include('pandora::livewire.partials.agent-form-actions')
            </form>
        </x-pandora::card>
    @endif

    {{-- -------------------------------------------------------------- limits --}}
    @if ($tab === 'limits')
        <x-pandora::card title="Limits &amp; autonomy">
            <x-slot:actions>
                @if ($canManage && ! $editing)
                    <button type="button" class="pd-btn pd-btn-sm" wire:click="startEditing">Edit</button>
                @endif
            </x-slot:actions>

            <form wire:submit="save" class="pd-stack">
                <div class="pd-field">
                    <label class="pd-label" for="pd-autonomy">Autonomy level</label>
                    <p class="pd-help">How far this agent may go without a human. See ADR-0009.</p>

                    @if ($locked('autonomy_level'))
                        <div class="pd-locked"><span class="pd-locked-mark" aria-hidden="true">◆</span>
                            {{ $agent->autonomy_level->label() }}</div>
                    @elseif ($editable)
                        <select id="pd-autonomy" class="pd-select" wire:model="autonomyLevel">
                            @foreach ($autonomyLevels as $level)
                                <option value="{{ $level->value }}">{{ $level->label() }}</option>
                            @endforeach
                        </select>
                        @error('autonomyLevel') <p class="pd-error">{{ $message }}</p> @enderror
                    @else
                        <p>{{ $agent->autonomy_level->label() }}</p>
                    @endif

                    @if ($agent->autonomy_level->allowsMutation())
                        <p class="pd-help">
                            This level permits mutating tool calls. What it can actually reach is still
                            decided by the tool policy and by the acting user's own authorization.
                        </p>
                    @endif
                </div>

                <div class="pd-grid pd-grid-split">
                    @php
                        $limitFields = [
                            ['max_iterations', 'pd-max-iterations', 'Max iterations', 'maxIterations', $agent->max_iterations, 'Model turns before the run stops itself.'],
                            ['max_tool_calls', 'pd-max-tool-calls', 'Max tool calls', 'maxToolCalls', $agent->max_tool_calls, 'Across the whole run, not per turn.'],
                            ['max_duration_seconds', 'pd-max-duration', 'Timeout (seconds)', 'maxDurationSeconds', $agent->max_duration_seconds, 'Wall clock, including time spent queued.'],
                            ['context_budget_tokens', 'pd-context-budget', 'Context budget (tokens)', 'contextBudgetTokens', $agent->context_budget_tokens, 'What the context pipeline may assemble.'],
                        ];
                    @endphp

                    @foreach ($limitFields as [$attribute, $id, $label, $property, $current, $help])
                        <div class="pd-field" wire:key="limit-{{ $attribute }}">
                            <label class="pd-label" for="{{ $id }}">{{ $label }}</label>
                            @if ($locked($attribute))
                                <div class="pd-locked"><span class="pd-locked-mark" aria-hidden="true">◆</span>
                                    <span class="pd-mono">{{ number_format($current) }}</span></div>
                            @elseif ($editable)
                                <input id="{{ $id }}" type="number" class="pd-input" wire:model="{{ $property }}">
                                @error($property) <p class="pd-error">{{ $message }}</p> @enderror
                            @else
                                <p class="pd-mono">{{ number_format($current) }}</p>
                            @endif
                            <p class="pd-help">{{ $help }}</p>
                        </div>
                    @endforeach
                </div>

                <h3 class="pd-section-title" style="margin-top: var(--pd-space-4)">Budgets</h3>
                <p class="pd-help">
                    Checked before each provider call, never after it. Empty means no budget at this
                    scope — the tenant and global budgets still apply.
                </p>

                <div class="pd-grid pd-grid-split">
                    <div class="pd-field">
                        <label class="pd-label" for="pd-token-budget">Token budget</label>
                        @if ($editable)
                            <input id="pd-token-budget" type="number" class="pd-input" wire:model="tokenBudget">
                            @error('tokenBudget') <p class="pd-error">{{ $message }}</p> @enderror
                        @else
                            <p class="pd-mono">{{ $agent->token_budget === null ? 'none' : number_format($agent->token_budget) }}</p>
                        @endif
                    </div>

                    <div class="pd-field">
                        <label class="pd-label" for="pd-cost-budget">Cost budget (minor units)</label>
                        @if ($editable)
                            <div class="pd-row">
                                <input id="pd-cost-budget" type="number" class="pd-input" style="max-width: 200px"
                                       wire:model="costBudget">
                                <label class="pd-visually-hidden" for="pd-currency">Currency</label>
                                <input id="pd-currency" type="text" class="pd-input" style="max-width: 90px"
                                       wire:model="currency" maxlength="3">
                            </div>
                            @error('costBudget') <p class="pd-error">{{ $message }}</p> @enderror
                            @error('currency') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">Minor units — 2500 is 25.00.</p>
                        @else
                            <p class="pd-mono">
                                {{ $agent->cost_budget_minor === null
                                    ? 'none'
                                    : number_format($agent->cost_budget_minor / 100, 2).' '.$agent->currency }}
                            </p>
                        @endif
                    </div>
                </div>

                @include('pandora::livewire.partials.agent-form-actions')
            </form>
        </x-pandora::card>
    @endif

    {{-- ---------------------------------------------------------------- runs --}}
    @if ($tab === 'runs')
        <x-pandora::card title="Recent runs" :padded="false">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr><th>Run</th><th>State</th><th>Model</th><th>Tokens</th><th>Started</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($runs as $run)
                            <tr wire:key="run-{{ $run->id }}">
                                <td>
                                    <a class="pd-link pd-mono"
                                       href="{{ route('pandora.runs.show', ['run' => $run->id]) }}"
                                       wire:navigate>{{ \Illuminate\Support\Str::limit($run->id, 12, '…') }}</a>
                                </td>
                                <td><x-pandora::badge :tone="$run->state->tone()">{{ $run->state->label() }}</x-pandora::badge></td>
                                <td class="pd-mono pd-faint">{{ $run->model_key ?? '—' }}</td>
                                <td class="pd-mono pd-faint">{{ number_format($run->input_tokens + $run->output_tokens) }}</td>
                                <td class="pd-muted">{{ $run->created_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-pandora::empty-state title="This agent has not run yet" :mark="false">
                                        Start it from the Chat page, or with
                                        <span class="pd-mono">pandora:agent:run {{ $agent->slug }}</span>.
                                    </x-pandora::empty-state>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-pandora::card>
    @endif

    {{-- --------------------------------------------------------------- usage --}}
    @if ($tab === 'usage')
        <x-pandora::card title="Usage">
            <div class="pd-grid pd-grid-stats">
                <div class="pd-stat">
                    <span class="pd-stat-label">Calls</span>
                    <span class="pd-stat-value">{{ number_format($usage['calls'] ?? 0) }}</span>
                </div>
                <div class="pd-stat">
                    <span class="pd-stat-label">Input tokens</span>
                    <span class="pd-stat-value">{{ number_format($usage['input_tokens'] ?? 0) }}</span>
                </div>
                <div class="pd-stat">
                    <span class="pd-stat-label">Output tokens</span>
                    <span class="pd-stat-value">{{ number_format($usage['output_tokens'] ?? 0) }}</span>
                </div>
                <div class="pd-stat">
                    <span class="pd-stat-label">Total tokens</span>
                    <span class="pd-stat-value">{{ number_format($usage['total_tokens'] ?? 0) }}</span>
                </div>

                @if ($canViewCosts)
                    <div class="pd-stat">
                        <span class="pd-stat-label">Cost</span>
                        <span class="pd-stat-value">
                            {{ ($usage['cost_micro'] ?? null) === null
                                ? 'unpriced'
                                : number_format($usage['cost_micro'] / 1000000, 4).' '.$agent->currency }}
                        </span>
                        @if (($usage['unpriced'] ?? 0) > 0)
                            <span class="pd-stat-meta">{{ $usage['unpriced'] }} unpriced call(s) excluded</span>
                        @endif
                    </div>
                @endif
            </div>

            <p class="pd-help" style="margin-top: var(--pd-space-4)">
                Everything recorded for this agent, all time.
                @unless ($canViewCosts)
                    Cost needs the <span class="pd-mono">pandora.costs.view</span> ability.
                @endunless
                The <a class="pd-link" href="{{ route('pandora.usage') }}" wire:navigate>Usage page</a>
                breaks this down by model and period.
            </p>
        </x-pandora::card>
    @endif

    {{-- ---------------------------------------------------------- skills --}}
    @if ($tab === 'skills')
        <x-pandora::card title="Skills" :padded="false">
            <x-slot:actions>
                <span class="pd-muted">Instructions, never code — ADR-0008</span>
            </x-slot:actions>

            @if ($skills->isEmpty())
                <div class="pd-card-body pd-muted">
                    No skills attached. A skill is a reusable body of instructions; attaching one
                    adds to what this agent knows how to do, and grants it nothing.
                </div>
            @else
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr><th>Skill</th><th>Version</th><th>Requires</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($skills as $skill)
                                <tr wire:key="agent-skill-{{ $skill->id }}">
                                    <td>
                                        <div class="pd-strong">{{ $skill->name }}</div>
                                        <div class="pd-mono pd-faint">{{ $skill->slug }}</div>
                                        @if ($skill->description)
                                            <div class="pd-muted">{{ $skill->description }}</div>
                                        @endif
                                    </td>
                                    <td class="pd-mono">{{ $skill->version }}</td>
                                    <td>
                                        @php $unmet = $skill->unmetToolRequirements($agent); @endphp

                                        @if (($skill->required_tools ?? []) === [])
                                            <span class="pd-muted">nothing</span>
                                        @else
                                            @foreach ($skill->required_tools as $tool)
                                                <x-pandora::badge :tone="in_array($tool, $unmet, true) ? 'danger' : 'muted'">{{ $tool }}</x-pandora::badge>
                                            @endforeach
                                        @endif

                                        @if ($unmet !== [])
                                            {{-- Surfaced, never resolved. Granting a tool because a
                                                 skill asked for it would make the skill the authority
                                                 on what the agent may do, which is exactly backwards. --}}
                                            <div class="pd-muted">This agent cannot call the tools in red.</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($skill->enabled)
                                            <x-pandora::badge tone="success">Enabled</x-pandora::badge>
                                        @else
                                            <x-pandora::badge>Disabled</x-pandora::badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-pandora::card>
    @endif

    {{-- ---------------------------------------------------------- memory --}}
    @if ($tab === 'memory')
        <x-pandora::card title="What this agent has written down" :padded="false">
            <x-slot:actions>
                @if ($canManageMemory)
                    <a class="pd-btn pd-btn-ghost" href="{{ route('pandora.memory') }}" wire:navigate>All memory</a>
                @endif
            </x-slot:actions>

            <div class="pd-card-body pd-muted">
                Agent-scoped memory only. What this agent can RETRIEVE also depends on who it is
                talking to, and an admin page has no "who is standing here" to bound that by —
                so anything belonging to a person lives on the Memory page, filtered by scope.
            </div>

            @if ($memories->isEmpty())
                <div class="pd-card-body pd-muted">Nothing yet.</div>
            @else
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr><th>Memory</th><th>Type</th><th>Status</th><th>Used</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($memories as $memory)
                                <tr wire:key="agent-memory-{{ $memory->id }}">
                                    <td>
                                        @if ($memory->title)
                                            <div class="pd-strong">{{ $memory->title }}</div>
                                        @endif
                                        <div>{{ $memory->content }}</div>
                                    </td>
                                    <td>{{ $memory->type->label() }}</td>
                                    <td>{{ $memory->status->label() }}</td>
                                    <td>{{ $memory->retrieval_count }}&times;</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-pandora::card>
    @endif

    {{-- -------------------------------------------------------- workspace --}}
    @if ($tab === 'workspace' && ! isset($pendingTabs['workspace']))
        <x-pandora::card title="Workspace">
            @if ($workspace === null)
                <p class="pd-muted">
                    This agent has no workspace, so it can reach no files at all. That is the
                    default, and it is the right one for an agent nobody has thought about yet.
                </p>
            @endif

            @if ($canManage)
                {{--
                    An agent holds at most one workspace. That is what lets the
                    file tools take no workspace argument -- there is nothing
                    for a sentence in a document the agent is reading to
                    select, because there is no selection.
                --}}
                <form wire:submit="attachWorkspace" class="pd-stack">
                    <div class="pd-field">
                        <label class="pd-label" for="pd-agent-workspace">Attached workspace</label>
                        <select id="pd-agent-workspace" class="pd-select" wire:model="workspaceId">
                            <option value="">None — this agent can reach no files</option>
                            @foreach ($workspaces as $option)
                                <option value="{{ $option->getKey() }}">
                                    {{ $option->name }} ({{ $option->disk }}:{{ $option->root_path }}){{ $option->enabled ? '' : ' — disabled' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="pd-help">
                            Attaching a workspace does not grant the file tools. The agent also needs
                            <span class="pd-mono">read_file</span>, <span class="pd-mono">write_file</span>
                            or <span class="pd-mono">list_files</span> in its tool allowlist, and each
                            is refused above the autonomy it needs.
                        </p>
                    </div>

                    <div class="pd-row">
                        <button type="submit" class="pd-btn pd-btn-primary">Save workspace</button>
                    </div>
                </form>
            @endif

            @if ($workspace !== null)
                <dl class="pd-details">
                    <dt>Name</dt>
                    <dd>{{ $workspace->name }}</dd>

                    <dt>Disk</dt>
                    <dd class="pd-mono">{{ $workspace->disk }}</dd>

                    <dt>Root</dt>
                    <dd class="pd-mono">{{ $workspace->root_path }}</dd>

                    <dt>Used</dt>
                    <dd>
                        {{ number_format($workspace->used_bytes) }} bytes
                        @if ($workspace->hasQuota())
                            of {{ number_format((int) $workspace->quota_bytes) }}
                        @else
                            (no quota)
                        @endif
                    </dd>

                    <dt>Allowed types</dt>
                    <dd>
                        @if (($workspace->allowed_mime_types ?? []) === [])
                            <span class="pd-muted">any</span>
                        @else
                            {{ implode(', ', $workspace->allowed_mime_types) }}
                        @endif
                    </dd>
                </dl>

                <a class="pd-btn pd-btn-ghost"
                   href="{{ route('pandora.workspaces', ['workspace' => $workspace->slug]) }}"
                   wire:navigate>Browse files</a>
            @endif
        </x-pandora::card>
    @endif

    {{-- ------------------------------------------------------- pending tabs --}}
    {{-- --------------------------------------------------------- automations --}}
    @if ($tab === 'automations')
        <x-pandora::card title="What starts this agent on its own" :padded="false">
            <x-slot:actions>
                @if ($canManageAutomations)
                    <a class="pd-btn pd-btn-ghost" href="{{ route('pandora.automations') }}" wire:navigate>All automations</a>
                @endif
            </x-slot:actions>

            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr><th>Automation</th><th>Trigger</th><th>Next run</th><th>Autonomy</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($automations as $automation)
                            <tr wire:key="agent-automation-{{ $automation->id }}">
                                <td>
                                    <a class="pd-link"
                                       href="{{ route('pandora.automations.show', ['automation' => $automation->slug]) }}"
                                       wire:navigate>{{ $automation->name }}</a>
                                    <div class="pd-mono pd-faint">{{ $automation->slug }}</div>
                                </td>
                                <td>{{ $automation->trigger_type->label() }}</td>
                                <td>
                                    @if (! $automation->enabled)
                                        <span class="pd-muted">—</span>
                                    @elseif ($automation->next_run_at !== null)
                                        {{ $automation->next_run_at->setTimezone($automation->timezone)->toDayDateTimeString() }}
                                    @else
                                        <span class="pd-muted">Waits for its trigger</span>
                                    @endif
                                </td>
                                <td>
                                    {{-- The EFFECTIVE level, not the stored one. An automation
                                         asking for more than this agent has gets this agent's,
                                         and showing the request rather than the outcome would
                                         misreport what the agent can actually do. --}}
                                    <x-pandora::badge :tone="$automation->effectiveAutonomy($agent)->allowsMutation() ? 'warning' : 'muted'">
                                        {{ $automation->effectiveAutonomy($agent)->label() }}
                                    </x-pandora::badge>
                                </td>
                                <td>
                                    @if ($automation->enabled)
                                        <x-pandora::badge tone="success">Enabled</x-pandora::badge>
                                    @else
                                        <x-pandora::badge tone="muted">Disabled</x-pandora::badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-pandora::empty-state title="Nothing starts this agent on its own">
                                        Automations are created on the
                                        <a class="pd-link" href="{{ route('pandora.automations') }}" wire:navigate>Automations</a>
                                        page. Whatever level one asks for, it can never exceed this agent's
                                        own ({{ $agent->autonomy_level->label() }}).
                                    </x-pandora::empty-state>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-pandora::card>
    @endif

    @if (isset($pendingTabs[$tab]))
        {{-- Untitled on purpose: the empty state already names the tab, and a
             card head above it repeated the word into an empty band. --}}
        <x-pandora::card>
            <x-pandora::empty-state :title="$pendingTabs[$tab]['label'].' is not here yet'">
                {{ $pendingTabs[$tab]['note'] }}
            </x-pandora::empty-state>
        </x-pandora::card>
    @endif
</div>
