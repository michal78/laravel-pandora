<?php

declare(strict_types=1);

namespace Pandora\Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Pandora\UI\PandoraGate;
use Pandora\Pandora\Usage\UsageRecord;

/**
 * What has been spent, by whom, on which model.
 *
 * Two abilities, not one. `pandora.usage.view` shows volume -- calls, tokens,
 * which models an application is actually leaning on. `pandora.costs.view`
 * shows money. Plenty of organisations want an engineer to see the first and
 * not the second, so the split is real rather than cosmetic, and the cost
 * columns are omitted from the query rather than hidden in the markup.
 */
final class UsageIndex extends Component
{
    #[Url(as: 'period', except: 'month')]
    public string $period = 'month';

    public function mount(): void
    {
        PandoraGate::authorize('usage.view');
    }

    public function render(): View
    {
        $canViewCosts = PandoraGate::allows('costs.view');
        $since = $this->since();

        $records = UsageRecord::query()
            ->when($since !== null, static fn ($query) => $query->where('occurred_at', '>=', $since))
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get();

        $byModel = $records
            ->groupBy(static fn (UsageRecord $record): string => $record->reference())
            ->map(static fn ($group, string $reference): array => [
                'reference' => $reference,
                'calls' => $group->sum('requests'),
                'input_tokens' => $group->sum('input_tokens'),
                'output_tokens' => $group->sum('output_tokens'),
                'total_tokens' => $group->sum('total_tokens'),
                // Null-safe on purpose: an unpriced model contributes nothing
                // rather than zero, and the view says "unpriced" rather than
                // printing a total that is quietly incomplete.
                'cost_micro' => $group->whereNotNull('cost_micro')->sum('cost_micro'),
                'unpriced' => $group->whereNull('cost_micro')->count(),
                'stale' => $group->where('pricing_stale', true)->count(),
            ])
            ->values();

        return view('pandora::livewire.usage-index', [
            'records' => $records,
            'byModel' => $byModel,
            'canViewCosts' => $canViewCosts,
            'totalTokens' => $records->sum('total_tokens'),
            'totalCalls' => $records->sum('requests'),
            'periodLabel' => $this->periodLabel(),
        ])->layout('pandora::layouts.app', ['title' => 'Usage']);
    }

    public function formatCost(?int $micro, string $currency = 'USD'): string
    {
        if ($micro === null || $micro === 0) {
            return '—';
        }

        return number_format($micro / 1_000_000, 4).' '.$currency;
    }

    private function since(): ?Carbon
    {
        return match ($this->period) {
            'day' => Carbon::now()->startOfDay(),
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            default => null,
        };
    }

    private function periodLabel(): string
    {
        return match ($this->period) {
            'day' => 'Today',
            'week' => 'This week',
            'month' => 'This month',
            default => 'All time',
        };
    }
}
