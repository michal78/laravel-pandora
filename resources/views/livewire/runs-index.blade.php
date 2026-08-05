<div class="pd-stack">
    <div class="pd-card">
        <div class="pd-card-head">
            <h2 class="pd-card-title">Runs</h2>
            <select class="pd-select" style="max-width: 200px" wire:model.live="stateFilter">
                <option value="">All states</option>
                @foreach ($states as $state)
                    <option value="{{ $state->value }}">{{ $state->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Run</th><th>State</th><th>Trigger</th><th>Model</th>
                        <th>Iterations</th><th>Tokens</th><th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr wire:key="run-{{ $run->getKey() }}">
                            <td>
                                <a href="{{ route('pandora.runs.show', $run->getKey()) }}" class="pd-mono">
                                    {{ \Illuminate\Support\Str::limit($run->getKey(), 12, '…') }}
                                </a>
                            </td>
                            <td>
                                <span class="pd-badge pd-badge-{{ $run->state->tone() }} {{ $run->state->isTerminal() ? '' : 'is-live' }}">
                                    {{ $run->state->label() }}
                                </span>
                            </td>
                            <td class="pd-muted">{{ $run->trigger_type->value }}</td>
                            <td class="pd-mono pd-muted">{{ $run->model_key ?? '—' }}</td>
                            <td>{{ $run->iterations }}</td>
                            <td class="pd-muted">{{ $run->input_tokens + $run->output_tokens }}</td>
                            <td class="pd-faint">{{ $run->created_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="pd-faint">No runs match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $runs->links() }}
</div>
