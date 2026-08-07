{{--
    One automation.

    The header answers the question people arrive with -- when does this run
    next, and is it on -- before any tab is opened. Everything below it is
    configuration, and configuration is not what anybody is looking at when an
    automation has not fired.
--}}
@php
    $editable = $canManage && $editing;
@endphp

<div class="pd-stack">
    <div class="pd-row">
        <div>
            <h2 class="pd-card-title" style="margin: 0">{{ $automation->name }}</h2>
            <span class="pd-mono pd-faint">{{ $automation->slug }}</span>
        </div>

        <div class="pd-row pd-row-end">
            @if ($automation->enabled)
                <x-pandora::badge tone="success">Enabled</x-pandora::badge>
            @else
                <x-pandora::badge tone="muted">Disabled</x-pandora::badge>
            @endif

            <x-pandora::badge tone="muted">{{ $automation->trigger_type->label() }}</x-pandora::badge>

            <x-pandora::badge :tone="$automation->autonomy_level->allowsMutation() ? 'warning' : 'muted'">
                {{ $automation->autonomy_level->label() }}
            </x-pandora::badge>

            @if ($canManage)
                <button type="button" class="pd-btn pd-btn-primary" wire:click="runNow">Run now</button>
            @endif

            <a class="pd-btn pd-btn-ghost" href="{{ route('pandora.automations') }}" wire:navigate>All automations</a>
        </div>
    </div>

    @if ($agent === null)
        <div class="pd-notice pd-notice-danger">
            The agent this automation binds to no longer exists. Every occurrence will be refused until
            it is pointed at one that does.
        </div>
    @endif

    @if (! $automation->enabled && $automation->disabled_reason !== null)
        <div class="pd-notice pd-notice-warning">
            Disabled {{ $automation->disabled_at?->diffForHumans() }}: {{ $automation->disabled_reason }}
        </div>
    @endif

    @if ($error !== null)
        <div class="pd-notice pd-notice-danger">{{ $error }}</div>
    @endif

    @if ($saved !== null)
        <div class="pd-notice pd-notice-success">{{ $saved }}</div>
    @endif

    <x-pandora::card title="Schedule">
        <div class="pd-grid pd-grid-3">
            <div>
                <div class="pd-faint">Next run</div>
                @if (! $automation->enabled)
                    <strong>Not scheduled</strong>
                    <div class="pd-muted">This automation is disabled.</div>
                @elseif ($automation->next_run_at !== null)
                    <strong>{{ $automation->next_run_at->setTimezone($automation->timezone)->toDayDateTimeString() }}</strong>
                    <div class="pd-faint pd-mono">{{ $automation->timezone }}</div>
                    <div class="pd-muted">{{ $automation->next_run_at->diffForHumans() }}</div>
                @else
                    <strong>Waits for its trigger</strong>
                    <div class="pd-muted">Woken from outside, so it has no schedule.</div>
                @endif
            </div>

            <div>
                <div class="pd-faint">Last run</div>
                @if ($automation->last_run_at !== null)
                    <strong>{{ $automation->last_run_at->setTimezone($automation->timezone)->toDayDateTimeString() }}</strong>
                    <div class="pd-muted">{{ $automation->last_run_at->diffForHumans() }}</div>
                @else
                    <strong>Never</strong>
                @endif
            </div>

            <div>
                <div class="pd-faint">Agent</div>
                @if ($agent !== null)
                    <a class="pd-link" href="{{ route('pandora.agents.show', ['agent' => $agent->slug]) }}"
                       wire:navigate><strong>{{ $agent->name }}</strong></a>
                    <div class="pd-muted">Autonomy ceiling: {{ $agent->autonomy_level->label() }}</div>
                @else
                    <strong class="pd-error">Missing</strong>
                @endif
            </div>
        </div>
    </x-pandora::card>

    <div class="pd-tabs" role="tablist">
        @php
            $tabs = [
                'overview' => 'Overview',
                'schedule' => 'Schedule',
                'behaviour' => 'Behaviour',
                'history' => 'History',
            ];

            if ($automation->trigger_type === \Pandora\Automation\Enums\AutomationTrigger::Webhook) {
                $tabs['webhook'] = 'Webhook';
            }
        @endphp

        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" wire:key="tab-{{ $key }}"
                    class="pd-tab {{ $tab === $key ? 'is-active' : '' }}"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    wire:click="selectTab('{{ $key }}')">{{ $label }}</button>
        @endforeach
    </div>

    {{-- ------------------------------------------------------------ overview --}}
    @if ($tab === 'overview')
        <x-pandora::card title="What it is, and what it asks">
            <x-slot:actions>
                @if ($canManage && ! $editing)
                    <button type="button" class="pd-btn pd-btn-ghost" wire:click="startEditing">Edit</button>
                @endif
            </x-slot:actions>

            <form wire:submit="save" class="pd-stack">
                <div class="pd-field">
                    <label class="pd-label" for="pd-automation-name">Name</label>
                    @if ($editable)
                        <input id="pd-automation-name" type="text" class="pd-input" wire:model="name">
                        @error('name') <p class="pd-error">{{ $message }}</p> @enderror
                    @else
                        <div>{{ $automation->name }}</div>
                    @endif
                </div>

                <div class="pd-field">
                    <label class="pd-label" for="pd-automation-description">Description</label>
                    @if ($editable)
                        <input id="pd-automation-description" type="text" class="pd-input" wire:model="description">
                        @error('description') <p class="pd-error">{{ $message }}</p> @enderror
                    @else
                        <div>{{ $automation->description ?? '—' }}</div>
                    @endif
                </div>

                <div class="pd-field">
                    <label class="pd-label" for="pd-automation-prompt">What the agent is asked</label>
                    @if (! $canViewPrompts)
                        {{-- Same rule as the agent's instructions: a prompt is the most
                             quietly sensitive thing on the page. --}}
                        <div class="pd-muted">
                            Hidden. Reading what this automation asks needs
                            <span class="pd-mono">pandora.prompts.view</span>.
                        </div>
                    @elseif ($editable)
                        <textarea id="pd-automation-prompt" class="pd-textarea" rows="6" wire:model="prompt"></textarea>
                        @error('prompt') <p class="pd-error">{{ $message }}</p> @enderror
                        <p class="pd-help">
                            Left empty, the agent is asked to decide whether anything needs doing and report.
                        </p>
                    @else
                        <pre class="pd-pre">{{ $automation->instruction() }}</pre>
                    @endif
                </div>

                @include('pandora::livewire.partials.automation-form-actions')
            </form>
        </x-pandora::card>

        @if ($canManage)
            <x-pandora::card title="Delete">
                <p class="pd-muted">
                    Deleting stops it firing and keeps its history. Runs it already started are unaffected.
                </p>
                <div class="pd-row" style="margin-top: var(--pd-space-3)">
                    <button type="button" class="pd-btn pd-btn-danger" wire:click="delete"
                            wire:confirm="Delete {{ $automation->name }}? It will stop firing.">
                        Delete automation
                    </button>
                </div>
            </x-pandora::card>
        @endif
    @endif

    {{-- ------------------------------------------------------------ schedule --}}
    @if ($tab === 'schedule')
        <x-pandora::card title="What wakes it">
            <x-slot:actions>
                @if ($canManage && ! $editing)
                    <button type="button" class="pd-btn pd-btn-ghost" wire:click="startEditing">Edit</button>
                @endif
            </x-slot:actions>

            <form wire:submit="save" class="pd-stack">
                <div class="pd-field">
                    <label class="pd-label" for="pd-automation-trigger">Trigger</label>
                    @if ($editable)
                        <select id="pd-automation-trigger" class="pd-select" wire:model.live="triggerType">
                            @foreach ($triggers as $trigger)
                                <option value="{{ $trigger->value }}">{{ $trigger->label() }}</option>
                            @endforeach
                        </select>
                        @error('triggerType') <p class="pd-error">{{ $message }}</p> @enderror
                    @else
                        <div>{{ $automation->trigger_type->label() }}</div>
                    @endif
                </div>

                <div class="pd-grid pd-grid-split">
                    <div class="pd-field">
                        <label class="pd-label" for="pd-automation-cron">Cron expression</label>
                        @if ($editable)
                            <input id="pd-automation-cron" type="text" class="pd-input pd-mono"
                                   placeholder="0 9 * * 1" wire:model="cronExpression">
                            @error('cronExpression') <p class="pd-error">{{ $message }}</p> @enderror
                        @else
                            <div class="pd-mono">{{ $automation->cron_expression ?? '—' }}</div>
                        @endif
                    </div>

                    <div class="pd-field">
                        <label class="pd-label" for="pd-automation-timezone">Timezone</label>
                        @if ($editable)
                            <select id="pd-automation-timezone" class="pd-select" wire:model="timezone">
                                @foreach ($timezones as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </select>
                            @error('timezone') <p class="pd-error">{{ $message }}</p> @enderror
                        @else
                            <div class="pd-mono">{{ $automation->timezone }}</div>
                        @endif
                        <p class="pd-help">
                            Occurrences are computed in this zone, so a 9am schedule stays 9am across
                            daylight saving rather than moving twice a year.
                        </p>
                    </div>
                </div>

                <div class="pd-grid pd-grid-split">
                    <div class="pd-field">
                        <label class="pd-label" for="pd-automation-interval">Interval (seconds)</label>
                        @if ($editable)
                            <input id="pd-automation-interval" type="number" class="pd-input" wire:model="intervalSeconds">
                            @error('intervalSeconds') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">
                                Minimum 60 — the scheduler ticks once a minute, so anything shorter
                                cannot be honoured.
                            </p>
                        @else
                            <div class="pd-mono">{{ $automation->interval_seconds ?? '—' }}</div>
                        @endif
                    </div>

                    <div class="pd-field">
                        <label class="pd-label" for="pd-automation-run-at">Run at (one-off)</label>
                        @if ($editable)
                            <input id="pd-automation-run-at" type="datetime-local" class="pd-input" wire:model="runAt">
                            @error('runAt') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">In the timezone selected above.</p>
                        @else
                            <div class="pd-mono">
                                {{ $automation->run_at?->setTimezone($automation->timezone)->toDayDateTimeString() ?? '—' }}
                            </div>
                        @endif
                    </div>
                </div>

                <div class="pd-field">
                    <label class="pd-label" for="pd-automation-event">Event class</label>
                    @if ($editable)
                        <input id="pd-automation-event" type="text" class="pd-input pd-mono"
                               placeholder="App\Events\OrderShipped" wire:model="eventClass">
                        @error('eventClass') <p class="pd-error">{{ $message }}</p> @enderror
                    @else
                        <div class="pd-mono">{{ $automation->event_class ?? '—' }}</div>
                    @endif
                    <p class="pd-help">
                        Used by the <strong>Event</strong> trigger. Pandora listens only for classes an
                        automation or a <span class="pd-mono">Pandora::on()</span> binding names.
                    </p>
                </div>

                @include('pandora::livewire.partials.automation-form-actions')
            </form>
        </x-pandora::card>
    @endif

    {{-- ----------------------------------------------------------- behaviour --}}
    @if ($tab === 'behaviour')
        <x-pandora::card title="How far it may go, and how often">
            <x-slot:actions>
                @if ($canManage && ! $editing)
                    <button type="button" class="pd-btn pd-btn-ghost" wire:click="startEditing">Edit</button>
                @endif
            </x-slot:actions>

            <form wire:submit="save" class="pd-stack">
                <div class="pd-field">
                    <label class="pd-label" for="pd-automation-autonomy">Autonomy level</label>
                    @if ($editable)
                        <select id="pd-automation-autonomy" class="pd-select" wire:model="autonomyLevel">
                            @foreach ($autonomyLevels as $level)
                                <option value="{{ $level->value }}">{{ $level->label() }}</option>
                            @endforeach
                        </select>
                        @error('autonomyLevel') <p class="pd-error">{{ $message }}</p> @enderror
                        @if ($agentLevel !== null)
                            <p class="pd-help">
                                Capped at the agent's own level ({{ $agentLevel->label() }}). An automation can
                                ask for less than its agent has and never for more — raise the agent's level
                                first if that is what you want.
                            </p>
                        @endif
                    @else
                        <div>{{ $automation->autonomy_level->label() }}</div>
                    @endif
                </div>

                <div class="pd-grid pd-grid-split">
                    <div class="pd-field">
                        <label class="pd-label" for="pd-automation-budget">Autonomy budget (runs per window)</label>
                        @if ($editable)
                            <input id="pd-automation-budget" type="number" class="pd-input" wire:model="autonomyBudgetRuns">
                            @error('autonomyBudgetRuns') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">
                                Exhausting it <strong>disables</strong> the automation and notifies an admin.
                                Empty means no limit, which is a decision worth making on purpose.
                            </p>
                        @else
                            <div class="pd-mono">{{ $automation->autonomy_budget_runs ?? 'no limit' }}</div>
                        @endif
                    </div>

                    <div class="pd-field">
                        <label class="pd-label" for="pd-automation-window">Budget window (seconds)</label>
                        @if ($editable)
                            <input id="pd-automation-window" type="number" class="pd-input" wire:model="autonomyBudgetWindow">
                            @error('autonomyBudgetWindow') <p class="pd-error">{{ $message }}</p> @enderror
                        @else
                            <div class="pd-mono">{{ $automation->autonomy_budget_window_seconds }}</div>
                        @endif
                    </div>
                </div>

                <div class="pd-grid pd-grid-split">
                    <div class="pd-field">
                        <label class="pd-label" for="pd-automation-concurrency">While a previous run is going</label>
                        @if ($editable)
                            <select id="pd-automation-concurrency" class="pd-select" wire:model="concurrencyPolicy">
                                @foreach ($concurrencyPolicies as $policy)
                                    <option value="{{ $policy->value }}">{{ $policy->label() }}</option>
                                @endforeach
                            </select>
                            @error('concurrencyPolicy') <p class="pd-error">{{ $message }}</p> @enderror
                        @else
                            <div>{{ $automation->concurrency_policy->label() }}</div>
                        @endif
                    </div>

                    <div class="pd-field">
                        <label class="pd-label" for="pd-automation-misfire">If occurrences were missed</label>
                        @if ($editable)
                            <select id="pd-automation-misfire" class="pd-select" wire:model="misfirePolicy">
                                @foreach ($misfirePolicies as $policy)
                                    <option value="{{ $policy->value }}">{{ $policy->label() }}</option>
                                @endforeach
                            </select>
                            @error('misfirePolicy') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">
                                A worker down for six hours should not come back to hundreds of stale runs.
                            </p>
                        @else
                            <div>{{ $automation->misfire_policy->label() }}</div>
                        @endif
                    </div>
                </div>

                <div class="pd-grid pd-grid-split">
                    <div class="pd-field">
                        <label class="pd-label" for="pd-automation-condition">Condition</label>
                        @if ($editable)
                            <select id="pd-automation-condition" class="pd-select" wire:model="conditionName">
                                <option value="">Always run</option>
                                @foreach ($conditions as $condition)
                                    <option value="{{ $condition }}">{{ $condition }}</option>
                                @endforeach
                            </select>
                            @error('conditionName') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">
                                Conditions are defined in
                                <span class="pd-mono">config/pandora.php</span> under
                                <span class="pd-mono">automation.conditions</span> — never here. A callable
                                stored in the database would be remote code execution with extra steps.
                            </p>
                        @else
                            <div class="pd-mono">{{ $automation->condition['name'] ?? 'always' }}</div>
                        @endif
                    </div>

                    <div class="pd-field">
                        <label class="pd-label" for="pd-automation-failures">Disable after N failures</label>
                        @if ($editable)
                            <input id="pd-automation-failures" type="number" class="pd-input" wire:model="disableAfterFailures">
                            @error('disableAfterFailures') <p class="pd-error">{{ $message }}</p> @enderror
                        @else
                            <div class="pd-mono">{{ $automation->failureLimit() ?? 'never' }}</div>
                        @endif
                        @if ($automation->consecutive_failures > 0)
                            <p class="pd-error">{{ $automation->consecutive_failures }} consecutive failures so far.</p>
                        @endif
                    </div>
                </div>

                @include('pandora::livewire.partials.automation-form-actions')
            </form>
        </x-pandora::card>
    @endif

    {{-- ------------------------------------------------------------- history --}}
    @if ($tab === 'history')
        @if ($automation->trigger_type === \Pandora\Automation\Enums\AutomationTrigger::Webhook)
            {{--
                Sends people to the right tab. A delivery refused before it
                became an occurrence -- a bad signature, a replay -- has nothing
                to show here by construction, and somebody who just got a 401 or
                a 409 will look here first.
            --}}
            <div class="pd-notice pd-notice-info">
                Occurrences are what this automation <em>ran</em>. A delivery that was refused before
                it got that far — a wrong signature, a stale timestamp, a repeat — never became one,
                and is on the <strong>Webhook</strong> tab under Deliveries.
            </div>
        @endif

        <x-pandora::card title="Occurrences" :padded="false">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr><th>Scheduled for</th><th>Outcome</th><th>Run</th><th>Why</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($occurrences as $occurrence)
                            <tr wire:key="occurrence-{{ $occurrence->id }}">
                                <td>
                                    <div>{{ $occurrence->scheduled_for->setTimezone($automation->timezone)->toDayDateTimeString() }}</div>
                                    <div class="pd-faint">{{ $occurrence->created_at?->diffForHumans() }}</div>
                                </td>
                                <td>
                                    <x-pandora::badge :tone="match ($occurrence->status->value) {
                                        'dispatched' => 'success',
                                        'skipped' => 'muted',
                                        'refused' => 'warning',
                                        'failed' => 'danger',
                                        default => 'info',
                                    }">{{ $occurrence->status->label() }}</x-pandora::badge>
                                </td>
                                <td>
                                    @if ($occurrence->run_id !== null)
                                        <a class="pd-link" href="{{ route('pandora.runs.show', ['run' => $occurrence->run_id]) }}"
                                           wire:navigate>{{ $occurrence->run?->state->label() ?? 'View run' }}</a>
                                    @else
                                        <span class="pd-muted">None</span>
                                    @endif
                                </td>
                                <td class="pd-muted">{{ $occurrence->error ?? $occurrence->reason ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <x-pandora::empty-state title="Nothing yet">
                                        Every occurrence is recorded here — including the ones that were
                                        skipped or refused, so that "it never fired" and "it fired and
                                        declined" can be told apart.
                                    </x-pandora::empty-state>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-pandora::card>
    @endif

    {{-- ------------------------------------------------------------- webhook --}}
    @if ($tab === 'webhook')
        <x-pandora::card title="Endpoint">
            <div class="pd-stack">
                <div class="pd-field">
                    <span class="pd-label">URL</span>
                    <pre class="pd-pre">POST {{ $webhookUrl }}</pre>
                </div>

                <div class="pd-field">
                    <span class="pd-label">Signature header</span>
                    <pre class="pd-pre">{{ config('pandora.automation.webhooks.signature_header') }}: {{ $signatureExample }}</pre>
                    <p class="pd-help">
                        <span class="pd-mono">v1</span> is an HMAC-SHA256 of
                        <span class="pd-mono">"{timestamp}.{raw body}"</span> using the secret. The timestamp is
                        inside the signature, so it cannot be rewritten, and a delivery more than
                        {{ config('pandora.automation.webhooks.tolerance_seconds') }}s old is refused.
                    </p>
                </div>

                @if ($revealedSecret !== null)
                    <div class="pd-notice pd-notice-warning">
                        <strong>Copy this now — it is not shown again.</strong>
                        <pre class="pd-pre">{{ $revealedSecret }}</pre>
                    </div>
                @endif

                @if ($canManage)
                    <div class="pd-row">
                        <button type="button" class="pd-btn pd-btn-primary" wire:click="rotateSecret"
                                wire:confirm="Generate a new secret? Anything signing with the current one will start failing.">
                            {{ $automation->webhook_secret === null ? 'Generate secret' : 'Rotate secret' }}
                        </button>
                    </div>
                    <p class="pd-help">
                        The secret is stored encrypted and never shown again after this. Rotating it
                        invalidates every signature made with the old one, so update the sender first.
                    </p>
                @endif
            </div>
        </x-pandora::card>

        <x-pandora::card title="Deliveries" :padded="false">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr><th>When</th><th>Outcome</th><th>From</th><th>Size</th><th>Run</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($deliveries as $delivery)
                            <tr wire:key="delivery-{{ $delivery->id }}">
                                <td>{{ $delivery->created_at?->diffForHumans() }}</td>
                                <td>
                                    @if ($delivery->status === \Pandora\Automation\WebhookDelivery::ACCEPTED)
                                        <x-pandora::badge tone="success">Accepted</x-pandora::badge>
                                    @else
                                        <x-pandora::badge tone="warning">Rejected</x-pandora::badge>
                                        <div class="pd-mono pd-faint">{{ $delivery->reason }}</div>
                                    @endif

                                    @if ($delivery->replay_count > 0)
                                        {{-- A replay cannot be its own row: the unique insert that
                                             refuses it is the replay protection. It is counted here
                                             instead, on the delivery it duplicates. --}}
                                        <div class="pd-faint">
                                            sent again {{ $delivery->replay_count }}&times;,
                                            last {{ $delivery->last_replayed_at?->diffForHumans() }}
                                        </div>
                                    @endif
                                </td>
                                <td class="pd-mono pd-faint">{{ $delivery->source_ip ?? '—' }}</td>
                                <td class="pd-mono pd-faint">{{ $delivery->payload_bytes }} B</td>
                                <td>
                                    @if ($delivery->run_id !== null)
                                        <a class="pd-link" href="{{ route('pandora.runs.show', ['run' => $delivery->run_id]) }}"
                                           wire:navigate>View run</a>
                                    @else
                                        <span class="pd-muted">None</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-pandora::empty-state title="No deliveries yet">
                                        Rejections are recorded here too. A run of them is usually a secret
                                        that was rotated on one side and not the other.
                                    </x-pandora::empty-state>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-pandora::card>
    @endif

    @unless ($canManage)
        <p class="pd-faint">
            Read-only. Editing and running automations needs the
            <span class="pd-mono">pandora.automations.manage</span> ability.
        </p>
    @endunless
</div>
