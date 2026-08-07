<?php

declare(strict_types=1);

namespace Pandora\Approvals;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Pandora\Approvals\Enums\ApprovalKind;
use Pandora\Approvals\Enums\ApprovalScope;
use Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Audit\AuditLogger;
use Pandora\Core\Actor\ActorContext;
use Pandora\Exceptions\ApprovalNotPending;
use Pandora\Exceptions\AuthorizationDenied;
use Pandora\Jobs\ResumeApprovedRun;
use Pandora\Runs\Run;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolDecision;
use Pandora\Tools\ToolExecution;
use Pandora\Tools\ToolInput;
use Pandora\UI\PandoraGate;

/**
 * Requests and resolves the human decisions a run waits on.
 *
 * Resolution is the security-critical part. It happens inside a transaction
 * with the approval row locked, so two approvers pressing the button at the
 * same moment produce exactly one decision and exactly one execution -- the
 * second gets ApprovalNotPending and changes nothing (threat T14).
 */
final class ApprovalManager
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly AuditLogger $audit,
        private readonly Config $config,
    ) {}

    /**
     * Record that a call is waiting, and park the execution alongside it.
     */
    public function request(
        Run $run,
        ToolExecution $execution,
        ToolDecision $decision,
        ?ActorContext $requestedBy,
    ): Approval {
        /** @var int $minutes */
        $minutes = $this->config->get('pandora.approvals.expires_after_minutes', 1440);

        /** @var Approval $approval */
        $approval = Approval::query()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->getKey(),
            'tool_execution_id' => $execution->getKey(),
            'tool_name' => $execution->tool_name,
            'tool_version' => $execution->tool_version,
            'risk_level' => $execution->risk_level->value,
            'summary' => $decision->tool?->summarize($decision->input ?? new ToolInput)
                ?? $execution->tool_name,
            'sanitized_arguments' => $execution->sanitized_arguments,
            'proposed_modifications' => $execution->argument_diff,
            'scope' => ApprovalScope::Once->value,
            'kind' => $decision->outcome->value === 'require_confirmation'
                ? ApprovalKind::Confirmation->value
                : ApprovalKind::Approval->value,
            'status' => ApprovalStatus::Pending->value,
            'requested_by_type' => $requestedBy?->type,
            'requested_by_id' => $requestedBy?->id,
            'expires_at' => now()->addMinutes($minutes),
            'metadata' => ['reason' => $decision->reason],
        ]);

        $execution->forceFill([
            'status' => ToolExecutionStatus::AwaitingApproval->value,
            'required_approval' => true,
            'approval_id' => $approval->getKey(),
        ])->save();

        $this->audit->record(
            action: 'approval.requested',
            targetType: Approval::class,
            targetId: (string) $approval->getKey(),
            runId: (string) $run->getKey(),
            severity: 'notice',
            metadata: [
                'tool' => $execution->tool_name,
                'risk' => $execution->risk_level->value,
                'reason' => $decision->reason,
                'arguments' => $execution->sanitized_arguments,
            ],
        );

        return $approval;
    }

    /**
     * @throws ApprovalNotPending when someone else got there first
     * @throws AuthorizationDenied when the resolver may not decide this
     */
    public function approve(
        Approval|string $approval,
        ?ActorContext $resolver = null,
        ApprovalScope $scope = ApprovalScope::Once,
        ?string $comment = null,
        bool $authorize = true,
    ): Approval {
        return $this->resolve($approval, ApprovalStatus::Approved, $resolver, $scope, $comment, $authorize);
    }

    /**
     * @throws ApprovalNotPending
     * @throws AuthorizationDenied
     */
    public function deny(
        Approval|string $approval,
        ?ActorContext $resolver = null,
        ?string $comment = null,
        bool $authorize = true,
    ): Approval {
        return $this->resolve(
            $approval,
            ApprovalStatus::Denied,
            $resolver,
            ApprovalScope::Once,
            $comment,
            $authorize,
        );
    }

    /**
     * Time out every approval past its window.
     *
     * A run whose approval expires FAILS with a specific reason rather than
     * waiting forever or, worse, proceeding.
     *
     * @return int how many were expired
     */
    public function expireOverdue(): int
    {
        $expired = 0;

        foreach (Approval::query()->overdue()->get() as $approval) {
            $this->resolve($approval, ApprovalStatus::Expired, null, ApprovalScope::Once, null, false);
            $expired++;
        }

        return $expired;
    }

    /**
     * A decision already made that covers this call.
     *
     * `run` scope covers further calls to the same tool in the same run;
     * `remembered` covers the same tool for the same actor until revoked.
     * `once` covers nothing beyond the call it was made about, which is why
     * it is the default.
     */
    public function coveringApproval(Run $run, string $toolName, ?ActorContext $actor): ?Approval
    {
        /** @var Approval|null $forRun */
        $forRun = Approval::query()
            ->where('run_id', $run->getKey())
            ->where('tool_name', $toolName)
            ->where('scope', ApprovalScope::Run->value)
            ->where('status', ApprovalStatus::Approved->value)
            ->latest('resolved_at')
            ->first();

        if ($forRun !== null) {
            return $forRun;
        }

        if ($this->config->get('pandora.approvals.allow_remembered', true) !== true || $actor === null) {
            return null;
        }

        /** @var Approval|null $remembered */
        $remembered = Approval::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('tool_name', $toolName)
            ->where('scope', ApprovalScope::Remembered->value)
            ->where('status', ApprovalStatus::Approved->value)
            ->where('requested_by_type', $actor->type)
            ->where('requested_by_id', $actor->id)
            ->latest('resolved_at')
            ->first();

        return $remembered;
    }

    /**
     * The one place an approval's status changes.
     *
     * Locked, checked and written in a single transaction: the check and the
     * write cannot be separated, which is what makes double resolution
     * impossible rather than merely unlikely.
     */
    private function resolve(
        Approval|string $approval,
        ApprovalStatus $status,
        ?ActorContext $resolver,
        ApprovalScope $scope,
        ?string $comment,
        bool $authorize,
    ): Approval {
        $id = $approval instanceof Approval ? (string) $approval->getKey() : $approval;

        $resolved = $this->connection->transaction(function () use (
            $id, $status, $resolver, $scope, $comment, $authorize
        ): Approval {
            /** @var Approval $fresh */
            $fresh = Approval::query()->lockForUpdate()->findOrFail($id);

            if (! $fresh->isPending()) {
                throw ApprovalNotPending::make($id, $fresh->status);
            }

            if ($authorize) {
                $this->assertMayResolve($fresh, $resolver);
            }

            // An approval whose window closed while it sat in the queue is
            // expired, not approved -- whatever the button said.
            $effective = $status === ApprovalStatus::Approved && $fresh->expires_at->isPast()
                ? ApprovalStatus::Expired
                : $status;

            $fresh->forceFill([
                'status' => $effective->value,
                'scope' => $scope->value,
                'comment' => $comment,
                'resolved_by_type' => $resolver?->type,
                'resolved_by_id' => $resolver?->id,
                'resolved_at' => now(),
            ])->save();

            return $fresh;
        });

        $this->audit->record(
            action: 'approval.'.$resolved->status->value,
            targetType: Approval::class,
            targetId: (string) $resolved->getKey(),
            runId: $resolved->run_id,
            severity: $resolved->status === ApprovalStatus::Approved ? 'notice' : 'info',
            metadata: [
                'tool' => $resolved->tool_name,
                'risk' => $resolved->risk_level->value,
                'scope' => $resolved->scope->value,
                'resolved_by' => $resolver?->jsonSerialize(),
                'comment' => $comment,
            ],
        );

        // Outside the transaction: a job dispatched inside one can be picked
        // up by a worker before the commit lands.
        //
        // The resumed call acts for the RUN's actor, never the approver. An
        // approver says a thing may be done; they do not thereby lend their
        // own authority to it, and re-authorization at execution time has to
        // ask about the right person to mean anything.
        /** @var Run|null $run */
        $run = Run::query()->find($resolved->run_id);

        ResumeApprovedRun::dispatch(
            (string) $resolved->getKey(),
            $run?->tenant_id,
            $run?->actor_type,
            $run?->actor_id,
        );

        return $resolved;
    }

    /**
     * @throws AuthorizationDenied
     */
    private function assertMayResolve(Approval $approval, ?ActorContext $resolver): void
    {
        if ($approval->isConfirmation()) {
            // The person who asked confirms their own request; anyone else
            // needs the ability, because confirming for somebody else is
            // approving.
            if ($resolver !== null
                && $resolver->type === $approval->requested_by_type
                && $resolver->id === $approval->requested_by_id) {
                return;
            }
        }

        if ($resolver === null || $resolver->authorizable === null) {
            throw AuthorizationDenied::forAbility(PandoraGate::ability('approvals.resolve'));
        }

        if (! PandoraGate::forUser($resolver->authorizable, 'approvals.resolve')) {
            throw AuthorizationDenied::forAbility(PandoraGate::ability('approvals.resolve'));
        }
    }
}
