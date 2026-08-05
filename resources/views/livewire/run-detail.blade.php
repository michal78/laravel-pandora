<div class="pd-stack"
     @if ($run && ! $run->state->isTerminal())
         wire:poll.{{ $pollIntervalMs }}ms
     @endif>

    @if (! $run)
        <div class="pd-card"><div class="pd-card-body pd-muted">Run not found.</div></div>
    @else
        <div class="pd-card">
            <div class="pd-card-head">
                <div class="pd-row">
                    <h2 class="pd-card-title">Run</h2>
                    <span class="pd-mono pd-faint">{{ $run->getKey() }}</span>
                    <span class="pd-badge pd-badge-{{ $run->state->tone() }} {{ $run->state->isTerminal() ? '' : 'is-live' }}">
                        {{ $run->state->label() }}
                    </span>
                </div>

                @if (! $run->state->isTerminal())
                    <button type="button" class="pd-btn pd-btn-sm pd-btn-danger" wire:click="cancel">Cancel</button>
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

                @if ($run->error_message)
                    <div class="pd-notice pd-notice-warning" style="margin-top: var(--pd-space-4)">
                        @if ($canViewTrace)
                            <strong class="pd-mono">{{ $run->error_class }}</strong>
                            <div class="pd-mono" style="margin-top:4px">{{ $run->error_message }}</div>
                        @else
                            This run failed. Internal details are visible to administrators only.
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-head">
                <h2 class="pd-card-title">Trace</h2>
                <span class="pd-faint">{{ $steps->count() }} steps</span>
            </div>

            <div class="pd-card-body">
                <div class="pd-timeline">
                    @forelse ($steps as $step)
                        @php $failed = $step->status === \Pandora\Pandora\Runs\Enums\RunStepStatus::Failed; @endphp

                        <div class="pd-step {{ $failed ? 'pd-step-failed' : '' }}" wire:key="step-{{ $step->getKey() }}">
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
            </div>
        </div>
    @endif
</div>
