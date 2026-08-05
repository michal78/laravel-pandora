<div class="pd-stack">
    <x-pandora::card :title="'Usage — ' . $periodLabel" :padded="false">
        <x-slot:actions>
            <label class="pd-visually-hidden" for="pd-usage-period">Period</label>
            <select id="pd-usage-period" class="pd-select" style="max-width: 200px" wire:model.live="period">
                <option value="day">Today</option>
                <option value="week">This week</option>
                <option value="month">This month</option>
                <option value="all">All time</option>
            </select>
        </x-slot:actions>

        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Model</th>
                        <th>Calls</th>
                        <th>Input</th>
                        <th>Output</th>
                        <th>Total tokens</th>
                        @if ($canViewCosts)
                            <th>Cost</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byModel as $row)
                        <tr wire:key="model-{{ $row['reference'] }}">
                            <td class="pd-mono">{{ $row['reference'] }}</td>
                            <td class="pd-mono">{{ number_format($row['calls']) }}</td>
                            <td class="pd-mono pd-faint">{{ number_format($row['input_tokens']) }}</td>
                            <td class="pd-mono pd-faint">{{ number_format($row['output_tokens']) }}</td>
                            <td class="pd-mono">{{ number_format($row['total_tokens']) }}</td>
                            @if ($canViewCosts)
                                <td class="pd-mono">
                                    {{ $this->formatCost($row['cost_micro']) }}
                                    @if ($row['unpriced'] > 0)
                                        {{-- Named rather than folded into the total: a partial
                                             sum presented as a whole one is worse than no sum. --}}
                                        <x-pandora::badge tone="neutral">{{ $row['unpriced'] }} unpriced</x-pandora::badge>
                                    @endif
                                    @if ($row['stale'] > 0)
                                        <x-pandora::badge tone="warning">stale pricing</x-pandora::badge>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewCosts ? 6 : 5 }}" class="pd-muted">
                                No usage recorded in this period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($byModel->isNotEmpty())
                    <tfoot>
                        <tr>
                            <th scope="row">Total</th>
                            <td class="pd-mono">{{ number_format($totalCalls) }}</td>
                            <td colspan="2"></td>
                            <td class="pd-mono">{{ number_format($totalTokens) }}</td>
                            @if ($canViewCosts)
                                <td class="pd-mono">
                                    {{ $this->formatCost($byModel->sum('cost_micro')) }}
                                </td>
                            @endif
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-pandora::card>

    <x-pandora::card title="Recent calls" :padded="false">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>When</th><th>Model</th><th>Tokens</th><th>Duration</th>
                        @if ($canViewCosts)
                            <th>Cost</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr wire:key="usage-{{ $record->id }}">
                            <td class="pd-faint">{{ $record->occurred_at?->diffForHumans() }}</td>
                            <td class="pd-mono">{{ $record->reference() }}</td>
                            <td class="pd-mono">{{ number_format($record->total_tokens) }}</td>
                            <td class="pd-mono pd-faint">{{ $record->duration_ms }} ms</td>
                            @if ($canViewCosts)
                                <td class="pd-mono">
                                    {{ $record->cost_micro === null ? 'unpriced' : $this->formatCost($record->cost_micro, $record->currency) }}
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canViewCosts ? 5 : 4 }}" class="pd-muted">Nothing yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pandora::card>
</div>
