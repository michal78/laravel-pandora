<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\UI\Feature;
use Pandora\UI\PandoraGate;
use Pandora\Workspaces\Workspace;
use Pandora\Workspaces\WorkspaceFiles;

/**
 * The files agents can reach, and how full each workspace is.
 *
 * Browsing goes through `WorkspaceFiles` rather than reading the disk
 * directly, which means the control center is subject to exactly the same
 * containment rules as an agent. That is not caution for its own sake: a page
 * that could show a file an agent cannot read would be a way to confirm what
 * lives outside the root, and the whole point of the root is that nobody finds
 * out.
 *
 * Usage is shown from the counter and can be recounted on demand, because the
 * counter is authoritative for enforcement but the filesystem is authoritative
 * for truth, and they can drift after a crash.
 */
final class WorkspacesIndex extends Component
{
    #[Url(as: 'workspace', except: '')]
    public string $selected = '';

    #[Url(as: 'path', except: '')]
    public string $path = '';

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function select(string $slug): void
    {
        $this->selected = $slug;
        $this->path = '';
        $this->notice = null;
        $this->error = null;
    }

    public function browse(string $path): void
    {
        $this->path = $path;
        $this->error = null;
    }

    public function up(): void
    {
        // Trimmed here rather than by passing `..` into the resolver. The
        // resolver would refuse it correctly at the root, but producing a
        // traversal in the UI to express "go up" means the one place that
        // must never see a traversal is routinely handed one.
        $this->path = str_contains($this->path, '/')
            ? substr($this->path, 0, (int) strrpos($this->path, '/'))
            : '';
    }

    public function recount(): void
    {
        PandoraGate::authorize('workspaces.access');

        $workspace = $this->workspace();

        if ($workspace === null) {
            return;
        }

        try {
            $bytes = $this->files($workspace)->reconcile();
            $this->notice = 'Recounted: '.number_format($bytes).' bytes.';
        } catch (WorkspaceDenied $e) {
            $this->error = $e->userMessage();
        }
    }

    public function render(): View
    {
        // Held back rather than removed. Nothing below runs while the feature
        // is off, so no workspace is listed, read or counted -- including for
        // an operator holding every ability, because this is not a question
        // about who is asking.
        if (Feature::disabled('workspaces')) {
            return view('pandora::livewire.workspaces-soon')
                ->layout('pandora::layouts.app', ['title' => 'Workspaces']);
        }

        $workspace = $this->workspace();
        $entries = [];
        $unreachable = false;

        if ($workspace !== null) {
            try {
                $entries = $this->files($workspace)->list($this->path);
            } catch (WorkspaceDenied) {
                // A root that has moved or been unmounted. Reported on the
                // page rather than thrown, because an operator arriving to
                // find out why an agent cannot read its files should see the
                // reason, not a stack trace.
                $unreachable = true;
            }
        }

        return view('pandora::livewire.workspaces-index', [
            'workspaces' => $this->workspaces(),
            'workspace' => $workspace,
            'entries' => $entries,
            'unreachable' => $unreachable,
            'canManage' => PandoraGate::allows('workspaces.access'),
        ])->layout('pandora::layouts.app', ['title' => 'Workspaces']);
    }

    /** @return Collection<int, Workspace> */
    private function workspaces(): Collection
    {
        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = Workspace::query()->orderBy('name')->get();

        return $workspaces;
    }

    private function workspace(): ?Workspace
    {
        if ($this->selected === '') {
            return null;
        }

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()->where('slug', $this->selected)->first();

        return $workspace;
    }

    private function files(Workspace $workspace): WorkspaceFiles
    {
        return new WorkspaceFiles($workspace, app(AuditLogger::class));
    }
}
