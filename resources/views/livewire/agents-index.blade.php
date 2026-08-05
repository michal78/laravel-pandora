{{--
    The agent roster.

    `Source` is the first column an operator needs after `Name`: whether a
    setting can be changed here at all depends on it, and finding that out by
    clicking into a locked form is a worse way to learn it.
--}}
<div class="pd-stack">
    @if ($error !== null)
        <div class="pd-notice pd-notice-danger">{{ $error }}</div>
    @endif

    <x-pandora::card title="Agents" :padded="false">
        <x-slot:actions>
            <label class="pd-visually-hidden" for="pd-agent-search">Search agents</label>
            <input id="pd-agent-search" type="search" class="pd-input" style="max-width: 220px"
                   placeholder="Search" wire:model.live.debounce.300ms="search">

            <label class="pd-visually-hidden" for="pd-agent-source">Filter by source</label>
            <select id="pd-agent-source" class="pd-select" style="max-width: 180px" wire:model.live="sourceFilter">
                <option value="">All sources</option>
                <option value="class">Class-defined</option>
                <option value="database">Database-defined</option>
            </select>

            @if ($canManage && ! $creating)
                <button type="button" class="pd-btn pd-btn-primary" wire:click="startCreating">New agent</button>
            @endif
        </x-slot:actions>

        @if ($creating)
            <div class="pd-card-body" style="border-bottom: 1px solid var(--pd-border)">
                <form wire:submit="create" class="pd-stack">
                    <div class="pd-grid pd-grid-split">
                        <div class="pd-field">
                            <label class="pd-label" for="pd-new-name">Name</label>
                            <input id="pd-new-name" type="text" class="pd-input" wire:model="newName" autofocus>
                            @error('newName') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">The slug is derived from this, and stays put if you rename later.</p>
                        </div>

                        <div class="pd-field">
                            <label class="pd-label" for="pd-new-description">Description <span class="pd-faint">(optional)</span></label>
                            <input id="pd-new-description" type="text" class="pd-input" wire:model="newDescription">
                            @error('newDescription') <p class="pd-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <p class="pd-help">
                        Created disabled, at <span class="pd-mono">observe_only</span> autonomy, with no tools.
                        Instructions, model and limits are set on the agent's own page.
                    </p>

                    <div class="pd-row">
                        <button type="submit" class="pd-btn pd-btn-primary">Create agent</button>
                        <button type="button" class="pd-btn pd-btn-ghost" wire:click="cancelCreating">Cancel</button>
                    </div>
                </form>
            </div>
        @endif

        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Agent</th><th>Source</th><th>Model</th>
                        <th>Autonomy</th><th>Status</th><th>Runs</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($agents as $agent)
                        <tr wire:key="agent-{{ $agent->id }}">
                            <td>
                                <a class="pd-link" href="{{ route('pandora.agents.show', ['agent' => $agent->slug]) }}"
                                   wire:navigate>{{ $agent->name }}</a>
                                <div class="pd-mono pd-faint">{{ $agent->slug }}</div>
                                @if ($agent->description !== null)
                                    <div class="pd-muted">{{ \Illuminate\Support\Str::limit($agent->description, 80) }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($agent->isClassDefined())
                                    @if ($registry->definitionIsInstalled($agent))
                                        <x-pandora::badge tone="info">Class</x-pandora::badge>
                                        <div class="pd-mono pd-faint">{{ class_basename($agent->definition_class) }}</div>
                                    @else
                                        {{-- The class is gone but the row survived. Saying so beats
                                             showing a class name that no longer exists. --}}
                                        <x-pandora::badge tone="warning">Orphaned</x-pandora::badge>
                                        <div class="pd-faint">Definition not installed</div>
                                    @endif
                                @else
                                    <x-pandora::badge tone="muted">Database</x-pandora::badge>
                                @endif
                            </td>
                            <td class="pd-mono pd-faint">
                                @if ($agent->default_model !== null)
                                    {{ $agent->default_provider }}:{{ $agent->default_model }}
                                @else
                                    <span class="pd-muted">deployment default</span>
                                @endif
                            </td>
                            <td>
                                <x-pandora::badge :tone="$agent->autonomy_level->allowsMutation() ? 'warning' : 'muted'">
                                    {{ $agent->autonomy_level->label() }}
                                </x-pandora::badge>
                            </td>
                            <td>
                                @if ($agent->enabled)
                                    <x-pandora::badge tone="success">Enabled</x-pandora::badge>
                                @else
                                    <x-pandora::badge tone="muted">Disabled</x-pandora::badge>
                                @endif
                            </td>
                            <td class="pd-mono pd-faint">{{ $runCounts[$agent->id] ?? 0 }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-pandora::empty-state title="No agents yet" :mark="true">
                                    Define one as a class implementing
                                    <span class="pd-mono">AgentDefinition</span> and register it under
                                    <span class="pd-mono">agents.definitions</span>, or create one here
                                    @if (! $canManage)
                                        — which needs <span class="pd-mono">pandora.agents.manage</span>.
                                    @else
                                        with <strong>New agent</strong>.
                                    @endif
                                </x-pandora::empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pandora::card>

    @unless ($canManage)
        <p class="pd-faint">
            Read-only. Creating and editing agents needs the
            <span class="pd-mono">pandora.agents.manage</span> ability.
        </p>
    @endunless
</div>
