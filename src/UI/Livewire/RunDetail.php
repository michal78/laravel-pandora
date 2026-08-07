<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Pandora\Runs\Run;
use Pandora\Runs\RunCanceller;
use Pandora\UI\PandoraGate;

/**
 * The run trace.
 *
 * `raw_meta` and internal error messages are shown only to holders of
 * `pandora.runs.trace.view` -- the trace is the most sensitive surface in the
 * product, and "authenticated" is not the same as "entitled to read it".
 */
final class RunDetail extends Component
{
    public string $runId = '';

    public function mount(string $run): void
    {
        PandoraGate::authorize('access');

        $this->runId = $run;
    }

    public function run(): ?Run
    {
        /** @var Run|null $run */
        $run = Run::query()->with('agent')->find($this->runId);

        return $run;
    }

    public function cancel(): void
    {
        $run = $this->run();

        if ($run === null) {
            return;
        }

        app(RunCanceller::class)->cancel($run, 'Cancelled from the control center.');
    }

    #[On('echo-private:pandora.run.{runId},.pandora.run.status_changed')]
    public function onStatusChanged(): void
    {
        // Re-render; state is re-read from the database.
    }

    public function render(): View
    {
        $run = $this->run();

        return view('pandora::livewire.run-detail', [
            'run' => $run,
            'steps' => $run?->steps()->get() ?? collect(),
            'canViewTrace' => PandoraGate::allows('runs.trace.view'),
            'pollIntervalMs' => (int) config('pandora.realtime.poll_interval_ms', 2500),
        ])->layout('pandora::layouts.app', ['title' => 'Run detail']);
    }
}
