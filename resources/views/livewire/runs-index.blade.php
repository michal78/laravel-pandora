<div class="pd-stack">
    <x-pandora::card title="Runs" :padded="false">
        <x-slot:actions>
            <label class="pd-visually-hidden" for="pd-state-filter">Filter by state</label>
            <select id="pd-state-filter" class="pd-select" style="max-width: 200px" wire:model.live="stateFilter">
                <option value="">All states</option>
                @foreach ($states as $state)
                    <option value="{{ $state->value }}">{{ $state->label() }}</option>
                @endforeach
            </select>
        </x-slot:actions>

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
                                <a href="{{ route('pandora.runs.show', $run->getKey()) }}" class="pd-mono pd-link">
                                    {{ \Illuminate\Support\Str::limit($run->getKey(), 12, '…') }}
                                </a>
                            </td>
                            <td><x-pandora::status :state="$run->state" /></td>
                            <td class="pd-muted">{{ $run->trigger_type->value }}</td>
                            <td class="pd-mono pd-muted">{{ $run->model_key ?? '—' }}</td>
                            <td>{{ $run->iterations }}</td>
                            <td class="pd-muted">{{ $run->input_tokens + $run->output_tokens }}</td>
                            <td class="pd-faint">{{ $run->created_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-pandora::empty-state title="No runs match this filter"
                                                        :mark="$stateFilter === ''">
                                    @if ($stateFilter === '')
                                        Runs appear here as soon as an agent is asked to do something.
                                    @else
                                        Try a different state, or clear the filter.
                                    @endif
                                </x-pandora::empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pandora::card>

    {{ $runs->links() }}
</div>
