<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Pandora\Approvals\Approval;
use Pandora\Approvals\ApprovalManager;
use Pandora\Approvals\Enums\ApprovalScope;
use Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Core\Actor\ActorManager;
use Pandora\Exceptions\ApprovalNotPending;
use Pandora\Exceptions\AuthorizationDenied;
use Pandora\UI\PandoraGate;

/**
 * The queue of decisions runs are waiting on.
 *
 * Resolving is gated on `pandora.approvals.resolve`, and gated again inside
 * ApprovalManager -- this page is a convenience, not the boundary.
 */
final class ApprovalsIndex extends Component
{
    use WithPagination;

    #[Url(as: 'status', except: 'pending')]
    public string $statusFilter = 'pending';

    public string $comment = '';

    public ?string $resolving = null;

    public ?string $error = null;

    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function select(?string $approvalId): void
    {
        $this->resolving = $approvalId;
        $this->comment = '';
        $this->error = null;
    }

    public function approve(string $approvalId, string $scope = 'once'): void
    {
        $this->resolve($approvalId, true, ApprovalScope::from($scope));
    }

    public function deny(string $approvalId): void
    {
        $this->resolve($approvalId, false, ApprovalScope::Once);
    }

    private function resolve(string $approvalId, bool $approved, ApprovalScope $scope): void
    {
        $this->error = null;

        $manager = app(ApprovalManager::class);
        $actor = app(ActorManager::class)->current();
        $comment = $this->comment === '' ? null : $this->comment;

        try {
            $approved
                ? $manager->approve($approvalId, $actor, $scope, $comment)
                : $manager->deny($approvalId, $actor, $comment);
        } catch (ApprovalNotPending|AuthorizationDenied $e) {
            // Both are ordinary outcomes here: somebody else got there first,
            // or this person may not decide. Neither is an error page.
            $this->error = $e->userMessage();
        }

        $this->resolving = null;
        $this->comment = '';
    }

    public function render(): View
    {
        $query = Approval::query()->latest('created_at');

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return view('pandora::livewire.approvals-index', [
            'approvals' => $query->paginate(20),
            'statuses' => ApprovalStatus::cases(),
            'scopes' => ApprovalScope::cases(),
            'canResolve' => PandoraGate::allows('approvals.resolve'),
            'canViewIo' => PandoraGate::allows('tools.io.view'),
            'allowRemembered' => config('pandora.approvals.allow_remembered', true) === true,
        ])->layout('pandora::layouts.app', ['title' => 'Approvals']);
    }
}
