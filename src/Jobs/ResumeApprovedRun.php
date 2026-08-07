<?php

declare(strict_types=1);

namespace Pandora\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pandora\Approvals\Approval;
use Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Conversations\Conversation;
use Pandora\Core\Actor\ActorManager;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Exceptions\ApprovalExpired;
use Pandora\Messages\MessageWriter;
use Pandora\Realtime\RunBroadcaster;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\RunStepStatus;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\Run;
use Pandora\Runs\RunStateMachine;
use Pandora\Runs\RunStepRecorder;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolCallCoordinator;
use Pandora\Tools\ToolExecution;

/**
 * Picks a run back up after a human decided.
 *
 * The decision itself was made and consumed transactionally by
 * ApprovalManager; this job carries out its consequence. It is deliberately
 * separate: resolving an approval is a web request, and a web request must not
 * wait on a tool call, a model turn, or the rest of a run.
 */
final class ResumeApprovedRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesPandoraContext;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $approvalId,
        public readonly ?string $tenantId = null,
        public readonly ?string $actorType = null,
        public readonly ?string $actorId = null,
        public readonly bool $synchronous = false,
    ) {
        $this->onQueue(self::queueName('agents'));
        $this->onConnection(self::queueConnection());
    }

    public function handle(
        RunStateMachine $states,
        ToolCallCoordinator $coordinator,
        RunStepRecorder $steps,
        MessageWriter $messages,
        RunBroadcaster $broadcaster,
        TenantManager $tenants,
        ActorManager $actors,
    ): void {
        $this->withPandoraContext($tenants, $actors, function () use (
            $states, $coordinator, $steps, $messages, $broadcaster, $actors
        ): void {
            /** @var Approval|null $approval */
            $approval = Approval::query()->find($this->approvalId);

            if ($approval === null || ! $approval->status->isResolved()) {
                return;
            }

            /** @var Run|null $run */
            $run = Run::query()->find($approval->run_id);

            if ($run === null || $run->state->isTerminal()) {
                return;
            }

            $execution = $approval->tool_execution_id === null
                ? null
                : ToolExecution::query()->find($approval->tool_execution_id);

            $steps->record(
                $run,
                RunStepType::ApprovalResponse,
                $approval->status === ApprovalStatus::Approved
                    ? RunStepStatus::Succeeded
                    : RunStepStatus::Failed,
                [
                    'approval_id' => $approval->getKey(),
                    'status' => $approval->status->value,
                    'scope' => $approval->scope->value,
                    'resolved_by' => $approval->resolved_by_type === null ? null : [
                        'type' => $approval->resolved_by_type,
                        'id' => $approval->resolved_by_id,
                    ],
                    'comment' => $approval->comment,
                ],
                label: $approval->status->label().': '.$approval->summary,
            );

            if ($execution === null) {
                return;
            }

            $approval->status === ApprovalStatus::Approved
                ? $this->proceed($run, $approval, $execution, $states, $coordinator, $broadcaster, $actors)
                : $this->refuse($run, $approval, $execution, $states, $coordinator, $messages, $broadcaster);
        });
    }

    /**
     * Approved: hand the call to the executor and put the run back to work.
     *
     * The call is still re-validated and re-authorized inside ExecuteToolCall.
     * An approval says a human is willing; it does not say the gates agree.
     */
    private function proceed(
        Run $run,
        Approval $approval,
        ToolExecution $execution,
        RunStateMachine $states,
        ToolCallCoordinator $coordinator,
        RunBroadcaster $broadcaster,
        ActorManager $actors,
    ): void {
        $execution->forceFill([
            'status' => ToolExecutionStatus::Pending->value,
            'approver_type' => $approval->resolved_by_type,
            'approver_id' => $approval->resolved_by_id,
        ])->save();

        if ($run->state === RunState::WaitingForApproval) {
            $previous = $run->state;
            $run = $states->transition($run, RunState::WaitingForTool);
            $broadcaster->stateChanged($run, $previous);
        }

        $coordinator->dispatch($run, [$execution], $actors->current(), $this->synchronous);
    }

    /**
     * Denied, expired or cancelled.
     *
     * A denial is ordinary: the model is told and the run carries on. An
     * expiry is not -- nobody decided, so the run fails with a reason that
     * says exactly that rather than a generic error.
     */
    private function refuse(
        Run $run,
        Approval $approval,
        ToolExecution $execution,
        RunStateMachine $states,
        ToolCallCoordinator $coordinator,
        MessageWriter $messages,
        RunBroadcaster $broadcaster,
    ): void {
        $reason = match ($approval->status) {
            ApprovalStatus::Expired => 'Nobody responded to the approval request before it expired.',
            ApprovalStatus::Cancelled => 'The approval request was cancelled.',
            default => 'A human declined this action.'
                .($approval->comment === null ? '' : ' '.$approval->comment),
        };

        $execution->forceFill([
            'status' => ToolExecutionStatus::Denied->value,
            'decision_reason' => $reason,
            'approver_type' => $approval->resolved_by_type,
            'approver_id' => $approval->resolved_by_id,
            'finished_at' => now(),
        ])->save();

        if ($run->conversation_id !== null) {
            /** @var Conversation|null $conversation */
            $conversation = Conversation::query()->find($run->conversation_id);

            if ($conversation !== null) {
                $message = $messages->toolResult(
                    $conversation,
                    $run,
                    $execution->tool_call_id,
                    $reason,
                    $execution->tool_name,
                    failed: true,
                );

                $broadcaster->messageCreated($message, $run->correlation_id);
            }
        }

        if ($approval->status === ApprovalStatus::Expired) {
            app(RunFailer::class)->fail($run, ApprovalExpired::make(
                (string) $approval->getKey(),
                $execution->tool_name,
            ));

            return;
        }

        if ($run->state === RunState::WaitingForApproval) {
            $previous = $run->state;
            $run = $states->transition($run, RunState::WaitingForTool);
            $broadcaster->stateChanged($run, $previous);
        }

        // A denial is a result like any other, so the ordinary fan-in applies:
        // if nothing else is outstanding, the model gets to respond to it.
        $coordinator->dispatch($run, [], null, $this->synchronous);
    }

    public function failed(\Throwable $exception): void
    {
        /** @var Approval|null $approval */
        $approval = Approval::query()->find($this->approvalId);

        if ($approval === null) {
            return;
        }

        /** @var Run|null $run */
        $run = Run::query()->find($approval->run_id);

        if ($run !== null && ! $run->state->isTerminal()) {
            app(RunFailer::class)->fail($run, $exception);
        }
    }
}
