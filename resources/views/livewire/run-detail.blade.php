<div class="pd-stack"
     @if ($run && ! $run->state->isTerminal())
         wire:poll.{{ $pollIntervalMs }}ms
     @endif>

    @if (! $run)
        <x-pandora::card>
            <x-pandora::empty-state title="Run not found">
                It may have been pruned, or it belongs to another workspace.
                <x-slot:actions>
                    <x-pandora::button :href="route('pandora.runs')" size="sm">All runs</x-pandora::button>
                </x-slot:actions>
            </x-pandora::empty-state>
        </x-pandora::card>
    @else
        <x-pandora::card :padded="false">
            <div class="pd-card-head">
                <div class="pd-row">
                    <h2 class="pd-card-title">Run</h2>
                    <span class="pd-mono pd-faint">{{ $run->getKey() }}</span>
                    <x-pandora::status :state="$run->state" />
                </div>

                @if (! $run->state->isTerminal())
                    <x-pandora::button variant="danger" size="sm" wire:click="cancel">Cancel</x-pandora::button>
                @endif
            </div>

            <div class="pd-card-body">
                <div class="pd-grid pd-grid-stats">
                    <div><div class="pd-stat-label">Agent</div><div class="pd-mono">{{ $run->agent?->name ?? '—' }}</div></div>
                    <div><div class="pd-stat-label">Trigger</div><div class="pd-mono">{{ $run->trigger_type->value }}</div></div>
                    <div><div class="pd-stat-label">Provider / model</div><div class="pd-mono">{{ $run->provider_key ?? '—' }} / {{ $run->model_key ?? '—' }}</div></div>
                    <div><div class="pd-stat-label">Iterations</div><div class="pd-mono">{{ $run->iterations }}</div></div>
                    <div><div class="pd-stat-label">Tokens</div><div class="pd-mono">{{ $run->input_tokens }} in / {{ $run->output_tokens }} out</div></div>
                    <div><div class="pd-stat-label">Duration</div><div class="pd-mono">{{ $run->durationMs() !== null ? $run->durationMs() . ' ms' : '—' }}</div></div>
                    <div><div class="pd-stat-label">Correlation</div><div class="pd-mono pd-faint">{{ \Illuminate\Support\Str::limit($run->correlation_id, 12, '…') }}</div></div>
                </div>

                @if (! $run->state->isTerminal())
                    <div class="pd-progress pd-progress-indeterminate" style="margin-top: var(--pd-space-4)"
                         role="progressbar" aria-label="Run in progress">
                        <div class="pd-progress-bar"></div>
                    </div>
                @endif

                @if ($run->error_message)
                    <div class="pd-notice pd-notice-danger" style="margin-top: var(--pd-space-4)">
                        @if ($canViewTrace)
                            <strong class="pd-mono">{{ $run->error_class }}</strong>
                            <div class="pd-mono" style="margin-top:4px">{{ $run->error_message }}</div>
                        @else
                            This run failed. Internal details are visible to administrators only.
                        @endif
                    </div>
                @endif
            </div>
        </x-pandora::card>

        {{--
            Delegation, in both directions, and only when there is any.

            The effective tools are shown because they are the answer to the
            question an incident asks: not "what may this agent do" but "what
            was this run allowed to do, and why". The list is frozen at
            delegation time, so it is the truth about this run even after
            somebody widens the agent.
        --}}
        @if ($parentRun || $childRuns->isNotEmpty())
            <x-pandora::card title="Delegation">
                @if ($parentRun)
                    <div class="pd-grid pd-grid-stats">
                        <div>
                            <div class="pd-stat-label">Delegated by</div>
                            <div>{{ $parentRun->agent?->name ?? 'an agent' }}</div>
                        </div>
                        <div>
                            <div class="pd-stat-label">Parent run</div>
                            <div>
                                <a href="{{ route('pandora.runs.show', $parentRun->getKey()) }}" class="pd-mono pd-link">
                                    {{ \Illuminate\Support\Str::limit($parentRun->getKey(), 12, '…') }}
                                </a>
                            </div>
                        </div>
                        <div>
                            <div class="pd-stat-label">Depth</div>
                            <div class="pd-mono">{{ $run->delegation_depth }}</div>
                        </div>
                    </div>

                    @if ($run->effective_tools !== null)
                        <div style="margin-top: var(--pd-space-4)">
                            <div class="pd-stat-label">Allowed to call</div>
                            @if ($run->effective_tools === [])
                                <div class="pd-faint">Nothing. The parent held no tool this agent also holds.</div>
                            @else
                                <div class="pd-mono">{{ implode(' · ', $run->effective_tools) }}</div>
                            @endif
                        </div>
                    @endif
                @endif

                @if ($childRuns->isNotEmpty())
                    <div @if ($parentRun) style="margin-top: var(--pd-space-4)" @endif>
                        <div class="pd-stat-label">Delegated to</div>
                        <table class="pd-table">
                            <thead>
                                <tr><th>Run</th><th>Agent</th><th>State</th><th>Started</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($childRuns as $child)
                                    <tr wire:key="child-{{ $child->getKey() }}">
                                        <td>
                                            <a href="{{ route('pandora.runs.show', $child->getKey()) }}" class="pd-mono pd-link">
                                                {{ \Illuminate\Support\Str::limit($child->getKey(), 12, '…') }}
                                            </a>
                                        </td>
                                        <td>{{ $child->agent?->name ?? '—' }}</td>
                                        <td><x-pandora::status :state="$child->state" /></td>
                                        <td class="pd-faint">{{ $child->created_at?->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-pandora::card>
        @endif

        <x-pandora::card title="Trace">
            <x-slot:actions>
                <span class="pd-faint">{{ $steps->count() }} steps</span>
            </x-slot:actions>

            <div class="pd-timeline">
                @forelse ($steps as $step)
                    @php
                        $failed = $step->status === \Pandora\Runs\Enums\RunStepStatus::Failed;
                        $running = $step->status === \Pandora\Runs\Enums\RunStepStatus::Started;
                    @endphp

                    <div class="pd-step {{ $failed ? 'pd-step-failed' : '' }} {{ $running ? 'pd-step-running' : '' }}"
                         wire:key="step-{{ $step->getKey() }}">
                        <div class="pd-step-dot" aria-hidden="true">{{ $step->sequence }}</div>

                        <div>
                            <div class="pd-step-head">
                                <span class="pd-step-type">{{ $step->type->label() }}</span>
                                @if ($step->label)
                                    <span class="pd-step-meta">{{ $step->label }}</span>
                                @endif
                                <span class="pd-step-meta pd-row-end">
                                    {{ $step->started_at?->format('H:i:s.v') }}
                                    @if ($step->duration_ms) &middot; {{ $step->duration_ms }} ms @endif
                                </span>
                            </div>

                            @if ($step->error_message)
                                <div class="pd-payload" style="color: var(--pd-danger)">{{ $step->error_class }}
{{ $step->error_message }}</div>
                            @endif

                            {{--
                                An argument diff is shown OPEN, not hidden behind
                                a disclosure: a policy that silently rewrote what
                                the model asked for is exactly the thing a person
                                reading a trace needs to see without looking.
                            --}}
                            @if (! empty($step->payload['argument_diff']))
                                <div class="pd-stack" style="margin-top: var(--pd-space-2)">
                                    <h4 class="pd-h4">Arguments changed by policy</h4>
                                    <table class="pd-table pd-table-tight">
                                        <thead>
                                            <tr><th>Field</th><th>Requested</th><th>Ran as</th></tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($step->payload['argument_diff'] as $change)
                                                <tr>
                                                    <td class="pd-mono">{{ $change['field'] }}</td>
                                                    <td class="pd-mono pd-diff-from">{{ json_encode($change['from']) }}</td>
                                                    <td class="pd-mono pd-diff-to">{{ json_encode($change['to']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            @if ($step->payload)
                                <details class="pd-details">
                                    <summary>Payload</summary>
                                    <div class="pd-payload">{{ json_encode($step->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</div>
                                </details>
                            @endif

                            @if ($canViewTrace && $step->raw_meta)
                                <details class="pd-details">
                                    <summary>Raw provider metadata (administrators)</summary>
                                    <div class="pd-payload">{{ json_encode($step->raw_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</div>
                                </details>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="pd-faint">No steps recorded yet.</p>
                @endforelse
            </div>
        </x-pandora::card>
    @endif
</div>
