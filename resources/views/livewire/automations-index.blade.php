{{--
    Everything that runs without anybody typing.

    `Next run` sits second because the question an operator arrives with is
    almost never "what automations exist" -- it is "why hasn't it run" or
    "when will it". Times are in each automation's OWN timezone, which is the
    one the person who configured it was thinking in.
--}}
<div class="pd-stack">
    @if ($error !== null)
        <div class="pd-notice pd-notice-danger">{{ $error }}</div>
    @endif

    @if ($notice !== null)
        <div class="pd-notice pd-notice-success">{{ $notice }}</div>
    @endif

    @if ($automations->isNotEmpty() && $schedulerSeenAt === null)
        {{--
            The single most common "automation problem" is not an automation
            problem: nobody is running `schedule:run`. Saying so beats letting
            somebody debug a cron expression that is perfectly correct.
        --}}
        <div class="pd-notice pd-notice-warning">
            No automation has ever fired. If that is unexpected, check that
            <span class="pd-mono">php artisan schedule:run</span> is running every minute on this host —
            Pandora registers its own entry, but something has to call the scheduler.
        </div>
    @endif

    <x-pandora::card title="Automations" :padded="false">
        <x-slot:actions>
            <label class="pd-visually-hidden" for="pd-automation-search">Search automations</label>
            <input id="pd-automation-search" type="search" class="pd-input" style="max-width: 220px"
                   placeholder="Search" wire:model.live.debounce.300ms="search">

            <label class="pd-visually-hidden" for="pd-automation-status">Filter by status</label>
            <select id="pd-automation-status" class="pd-select" style="max-width: 160px" wire:model.live="statusFilter">
                <option value="">All</option>
                <option value="enabled">Enabled</option>
                <option value="disabled">Disabled</option>
            </select>

            @if ($canManage && ! $creating)
                <button type="button" class="pd-btn pd-btn-primary" wire:click="startCreating">New automation</button>
            @endif
        </x-slot:actions>

        @if ($creating)
            <div class="pd-card-body" style="border-bottom: 1px solid var(--pd-border)">
                <form wire:submit="create" class="pd-stack">
                    <div class="pd-grid pd-grid-split">
                        <div class="pd-field">
                            <label class="pd-label" for="pd-new-automation-name">Name</label>
                            <input id="pd-new-automation-name" type="text" class="pd-input" wire:model="newName" autofocus>
                            @error('newName') <p class="pd-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="pd-field">
                            <label class="pd-label" for="pd-new-automation-agent">Agent</label>
                            <select id="pd-new-automation-agent" class="pd-select" wire:model="newAgent">
                                <option value="">Choose an agent</option>
                                @foreach ($agents as $agent)
                                    <option value="{{ $agent->slug }}">{{ $agent->name }}</option>
                                @endforeach
                            </select>
                            @error('newAgent') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">
                                The automation can never exceed this agent's autonomy level.
                            </p>
                        </div>
                    </div>

                    <p class="pd-help">
                        Created <strong>disabled</strong>, one-off, at <span class="pd-mono">observe_only</span>.
                        The schedule and what it asks the agent are set on its own page.
                    </p>

                    <div class="pd-row">
                        <button type="submit" class="pd-btn pd-btn-primary">Create automation</button>
                        <button type="button" class="pd-btn pd-btn-ghost" wire:click="cancelCreating">Cancel</button>
                    </div>
                </form>
            </div>
        @endif

        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Automation</th><th>Next run</th><th>Trigger</th>
                        <th>Agent</th><th>Autonomy</th><th>Last run</th><th>Status</th>
                        @if ($canManage) <th></th> @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($automations as $automation)
                        <tr wire:key="automation-{{ $automation->id }}">
                            <td>
                                <a class="pd-link"
                                   href="{{ route('pandora.automations.show', ['automation' => $automation->slug]) }}"
                                   wire:navigate>{{ $automation->name }}</a>
                                <div class="pd-mono pd-faint">{{ $automation->slug }}</div>
                                @if ($automation->disabled_reason !== null && ! $automation->enabled)
                                    <div class="pd-muted">{{ \Illuminate\Support\Str::limit($automation->disabled_reason, 90) }}</div>
                                @endif
                            </td>
                            <td>
                                @if (! $automation->enabled)
                                    <span class="pd-muted">—</span>
                                @elseif ($automation->next_run_at !== null)
                                    <div>{{ $automation->next_run_at->setTimezone($automation->timezone)->toDayDateTimeString() }}</div>
                                    <div class="pd-faint pd-mono">{{ $automation->timezone }}</div>
                                @else
                                    {{-- Not a gap: event and webhook automations are woken from
                                         outside and have no schedule by construction. --}}
                                    <span class="pd-muted">Waits for its trigger</span>
                                @endif
                            </td>
                            <td>
                                <x-pandora::badge tone="muted">{{ $automation->trigger_type->label() }}</x-pandora::badge>
                                <div class="pd-mono pd-faint">
                                    {{ $automation->cron_expression
                                        ?? ($automation->interval_seconds !== null ? 'every '.$automation->interval_seconds.'s' : '') }}
                                </div>
                            </td>
                            <td>
                                @if ($automation->agent !== null)
                                    <a class="pd-link" href="{{ route('pandora.agents.show', ['agent' => $automation->agent->slug]) }}"
                                       wire:navigate>{{ $automation->agent->name }}</a>
                                @else
                                    <x-pandora::badge tone="danger">Missing</x-pandora::badge>
                                @endif
                            </td>
                            <td>
                                <x-pandora::badge :tone="$automation->autonomy_level->allowsMutation() ? 'warning' : 'muted'">
                                    {{ $automation->autonomy_level->label() }}
                                </x-pandora::badge>
                            </td>
                            <td>
                                @if ($automation->last_run_at !== null)
                                    <div>{{ $automation->last_run_at->diffForHumans() }}</div>
                                    @if ($automation->consecutive_failures > 0)
                                        <div class="pd-error">{{ $automation->consecutive_failures }} failed in a row</div>
                                    @endif
                                @else
                                    <span class="pd-muted">Never</span>
                                @endif
                            </td>
                            <td>
                                @if ($automation->enabled)
                                    <x-pandora::badge tone="success">Enabled</x-pandora::badge>
                                @else
                                    <x-pandora::badge tone="muted">Disabled</x-pandora::badge>
                                @endif
                            </td>
                            @if ($canManage)
                                <td>
                                    <button type="button" class="pd-btn pd-btn-ghost"
                                            wire:click="toggle('{{ $automation->id }}')">
                                        {{ $automation->enabled ? 'Disable' : 'Enable' }}
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManage ? 8 : 7 }}">
                                <x-pandora::empty-state title="No automations yet" :mark="true">
                                    An automation starts a run without anybody typing — on a schedule,
                                    on a Laravel event, or on a signed webhook.
                                    @if ($canManage)
                                        Create one with <strong>New automation</strong>.
                                    @else
                                        Creating one needs <span class="pd-mono">pandora.automations.manage</span>.
                                    @endif
                                </x-pandora::empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pandora::card>

    @if ($canManage && $observations->isNotEmpty())
        {{--
            The goal queue. Agents may propose work for themselves and may not
            schedule it; this is where a person decides. It lives beside the
            automations because promoting one produces an automation.
        --}}
        <x-pandora::card title="Proposed by agents" :padded="false">
            <x-slot:actions>
                <span class="pd-faint">{{ $observations->count() }} awaiting a decision</span>
            </x-slot:actions>

            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr><th>Proposal</th><th>Agent</th><th>Suggested</th><th>Proposed</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($observations as $observation)
                            <tr wire:key="observation-{{ $observation->id }}">
                                <td>
                                    <strong>{{ $observation->title }}</strong>
                                    <div class="pd-muted">{{ \Illuminate\Support\Str::limit($observation->proposal, 140) }}</div>
                                    @if ($observation->rationale !== null)
                                        <div class="pd-faint">{{ \Illuminate\Support\Str::limit($observation->rationale, 120) }}</div>
                                    @endif
                                </td>
                                <td>{{ $observation->agent?->name ?? '—' }}</td>
                                <td class="pd-mono pd-faint">{{ $observation->suggested_cron ?? '—' }}</td>
                                <td class="pd-faint">{{ $observation->created_at?->diffForHumans() }}</td>
                                <td>
                                    <div class="pd-row">
                                        <button type="button" class="pd-btn pd-btn-primary"
                                                wire:click="promote('{{ $observation->id }}')">Promote</button>
                                        <button type="button" class="pd-btn pd-btn-ghost"
                                                wire:click="dismiss('{{ $observation->id }}')">Dismiss</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pd-card-body">
                <p class="pd-help">
                    Promoting creates a <strong>disabled</strong> one-off automation at
                    <span class="pd-mono">observe_only</span>. The suggested schedule is carried across as a
                    note, not obeyed — an agent proposes when; you decide whether.
                </p>
            </div>
        </x-pandora::card>
    @endif

    @unless ($canManage)
        <p class="pd-faint">
            Read-only. Creating, editing and running automations needs the
            <span class="pd-mono">pandora.automations.manage</span> ability.
        </p>
    @endunless
</div>
