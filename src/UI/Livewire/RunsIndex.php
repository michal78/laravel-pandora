<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Runs\RunCanceller;
use Pandora\UI\PandoraGate;

final class RunsIndex extends Component
{
    use WithPagination;

    #[Url(as: 'state', except: '')]
    public string $stateFilter = '';

    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function updatedStateFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Cancel from the list, on the same path as the detail page.
     *
     * A run that has already reached a terminal state is left alone rather
     * than erroring: this list polls, so the row a person clicked may have
     * finished between the render and the click.
     */
    public function cancel(string $runId): void
    {
        PandoraGate::authorize('access');

        $run = Run::query()->find($runId);

        if ($run === null || $run->state->isTerminal()) {
            return;
        }

        app(RunCanceller::class)->cancel($run, 'Cancelled from the control center.');
    }

    public function render(): View
    {
        $query = Run::query()->with('agent')->latest('created_at');

        if ($this->stateFilter !== '') {
            $query->where('state', $this->stateFilter);
        }

        $runs = $query->paginate(25);

        // Poll only while something on this page can still change. A list of
        // finished runs that re-queries every 2.5 seconds is load with no
        // question behind it.
        $hasActiveRuns = $runs->getCollection()
            ->contains(fn (Run $run): bool => ! $run->state->isTerminal());

        return view('pandora::livewire.runs-index', [
            'runs' => $runs,
            'states' => RunState::cases(),
            'hasActiveRuns' => $hasActiveRuns,
            'pollIntervalMs' => (int) config('pandora.realtime.poll_interval_ms', 2500),
        ])->layout('pandora::layouts.app', ['title' => 'Runs']);
    }
}
