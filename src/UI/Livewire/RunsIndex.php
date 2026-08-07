<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
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

    public function render(): View
    {
        $query = Run::query()->latest('created_at');

        if ($this->stateFilter !== '') {
            $query->where('state', $this->stateFilter);
        }

        return view('pandora::livewire.runs-index', [
            'runs' => $query->paginate(25),
            'states' => RunState::cases(),
        ])->layout('pandora::layouts.app', ['title' => 'Runs']);
    }
}
