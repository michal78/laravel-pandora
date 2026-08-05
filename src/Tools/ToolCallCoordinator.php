<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools;

use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Approvals\ApprovalManager;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Conversations\Session;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Jobs\ContinueAgentRun;
use Pandora\Pandora\Jobs\ExecuteToolCall;
use Pandora\Pandora\Messages\MessageWriter;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Realtime\RunBroadcaster;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Enums\RunStepStatus;
use Pandora\Pandora\Runs\Enums\RunStepType;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Runs\RunStepRecorder;
use Pandora\Pandora\Support\Redactor;
use Pandora\Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Pandora\Tools\Enums\ToolExecutionStatus;

/**
 * Turns a model's tool REQUESTS into decided, recorded, dispatched work.
 *
 * Every call gets a row before anything happens to it, so a denied call and a
 * paused call are as visible in the trace as a successful one. Nothing here
 * executes a tool: this decides, records, and hands off.
 */
final class ToolCallCoordinator
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolGatekeeper $gatekeeper,
        private readonly ApprovalManager $approvals,
        private readonly RunStepRecorder $steps,
        private readonly AuditLogger $audit,
        private readonly Redactor $redactor,
        private readonly MessageWriter $messages,
        private readonly RunBroadcaster $broadcaster,
    ) {}

    /**
     * Decide and record every call in one model turn.
     *
     * @param list<ToolCall> $calls
     * @return list<ToolExecution>
     */
    public function decide(
        Run $run,
        Agent $agent,
        Session $session,
        ?ActorContext $actor,
        array $calls,
    ): array {
        $executions = [];

        foreach ($calls as $call) {
            $executions[] = $this->decideOne($run, $agent, $session, $actor, $call);
        }

        $run->forceFill(['tool_calls_count' => $run->tool_calls_count + count($calls)])->save();

        return $executions;
    }

    /**
     * The state a run enters once its calls are decided.
     *
     * Waiting for a human beats waiting for a machine: if anything needs
     * approval the run parks there, and any tool jobs dispatched alongside it
     * still run, still record, and still fan in.
     *
     * @param list<ToolExecution> $executions
     */
    public function nextState(array $executions): RunState
    {
        foreach ($executions as $execution) {
            if ($execution->status === ToolExecutionStatus::AwaitingApproval) {
                return RunState::WaitingForApproval;
            }
        }

        return RunState::WaitingForTool;
    }

    /**
     * Hand the ready calls off, or continue the run if there are none.
     *
     * Called AFTER the run's state transition is committed: a worker that
     * picks the job up instantly must not find a run still claiming to be
     * `running`.
     *
     * @param list<ToolExecution> $executions
     */
    public function dispatch(
        Run $run,
        array $executions,
        ?ActorContext $actor = null,
        bool $synchronous = false,
    ): void {
        $dispatched = 0;

        foreach ($executions as $execution) {
            if ($execution->status !== ToolExecutionStatus::Pending) {
                continue;
            }

            // The actor travels with the job: re-authorization at execution
            // time is meaningless if it runs as nobody.
            ExecuteToolCall::dispatch(
                (string) $execution->getKey(),
                $run->tenant_id,
                $synchronous,
                $actor?->type,
                $actor?->id,
            );

            $dispatched++;
        }

        // Every call was refused or parked. A run with nothing outstanding and
        // nothing pending would otherwise sit in `waiting_for_tool` forever,
        // when what it should do is let the model answer.
        if ($dispatched === 0 && $this->openCount($run) === 0) {
            ContinueAgentRun::dispatch(
                (string) $run->getKey(),
                $run->tenant_id,
                $actor?->type,
                $actor?->id,
                $synchronous,
            );
        }
    }

    /**
     * How many of a run's calls are still open.
     *
     * The fan-in question, asked by the last tool job to finish under a lock
     * on the run row — which is what makes "exactly one continuation" true
     * rather than probable.
     */
    public function openCount(Run $run): int
    {
        return ToolExecution::query()
            ->where('run_id', $run->getKey())
            ->open()
            ->count();
    }

    private function decideOne(
        Run $run,
        Agent $agent,
        Session $session,
        ?ActorContext $actor,
        ToolCall $call,
    ): ToolExecution {
        $context = new ToolContext(
            run: $run,
            agent: $agent,
            session: $session,
            actor: $actor,
            toolCallId: $call->id,
        );

        $decision = $this->isDuplicate($run, $call)
            ? ToolDecision::deny(
                $call,
                AuthorizationLayer::Budget,
                'This exact call was already made in this run. Use the result you already have, '
                .'or call with different arguments.',
                $this->registry->find($call->name),
            )
            : $this->gatekeeper->evaluate($call, $context);

        $execution = $this->record($run, $call, $decision);

        $this->steps->record(
            $run,
            RunStepType::ToolRequest,
            $decision->isDenied() ? RunStepStatus::Failed : RunStepStatus::Started,
            $decision->toTrace() + ['arguments' => $execution->sanitized_arguments],
            label: $decision->tool?->summarize($decision->input ?? new ToolInput) ?? $call->name,
        );

        $this->audit->record(
            action: $decision->isDenied() ? 'tool.denied' : 'tool.requested',
            targetType: ToolExecution::class,
            targetId: (string) $execution->getKey(),
            runId: (string) $run->getKey(),
            severity: $decision->isDenied() ? 'notice' : 'info',
            metadata: $decision->toTrace() + ['arguments' => $execution->sanitized_arguments],
        );

        if ($decision->wasModified()) {
            $this->audit->record(
                action: 'tool.arguments_modified',
                targetType: ToolExecution::class,
                targetId: (string) $execution->getKey(),
                runId: (string) $run->getKey(),
                severity: 'notice',
                metadata: [
                    'tool' => $call->name,
                    'diff' => $this->redactor->redact($decision->diff?->toArray() ?? []),
                    'reason' => $decision->reason,
                ],
            );
        }

        if ($decision->isDenied()) {
            // A refusal is a RESULT. Without one the model's next request
            // would carry a tool call nobody answered -- which providers
            // reject -- and the model would never learn why it was refused.
            $this->writeResult($run, $execution, $decision->modelMessage());

            $execution->forceFill(['finished_at' => now()])->save();

            return $execution;
        }

        if ($decision->pausesRun()) {
            return $this->pause($run, $execution, $decision, $actor);
        }

        return $execution;
    }

    /**
     * Park a call for a human, unless a decision already made covers it.
     */
    private function pause(
        Run $run,
        ToolExecution $execution,
        ToolDecision $decision,
        ?ActorContext $actor,
    ): ToolExecution {
        $covering = $this->approvals->coveringApproval($run, $execution->tool_name, $actor);

        if ($covering !== null) {
            $execution->forceFill([
                'status' => ToolExecutionStatus::Pending->value,
                'required_approval' => true,
                'approval_id' => $covering->getKey(),
                'approver_type' => $covering->resolved_by_type,
                'approver_id' => $covering->resolved_by_id,
                'decision_reason' => 'Covered by an existing '.$covering->scope->value.' approval.',
            ])->save();

            $this->steps->record(
                $run,
                RunStepType::ApprovalResponse,
                RunStepStatus::Succeeded,
                ['approval_id' => $covering->getKey(), 'scope' => $covering->scope->value],
                label: 'Covered by an earlier approval',
            );

            return $execution;
        }

        $approval = $this->approvals->request($run, $execution, $decision, $actor);

        $this->steps->record(
            $run,
            RunStepType::ApprovalRequest,
            RunStepStatus::Started,
            [
                'approval_id' => $approval->getKey(),
                'tool' => $execution->tool_name,
                'risk' => $execution->risk_level->value,
                'reason' => $decision->reason,
                'expires_at' => $approval->expires_at->toIso8601String(),
            ],
            label: $approval->summary,
        );

        return $execution->refresh();
    }

    /**
     * Record a refusal as a tool result message the model will read.
     */
    private function writeResult(Run $run, ToolExecution $execution, string $content): void
    {
        if ($run->conversation_id === null) {
            return;
        }

        /** @var Conversation|null $conversation */
        $conversation = Conversation::query()->find($run->conversation_id);

        if ($conversation === null) {
            return;
        }

        $message = $this->messages->toolResult(
            $conversation,
            $run,
            $execution->tool_call_id,
            $content,
            $execution->tool_name,
            failed: true,
        );

        $this->broadcaster->messageCreated($message, $run->correlation_id);
    }

    /**
     * Has this run already made this exact call?
     *
     * Catches the loop where a model re-asks the same question because it did
     * not like the answer. The idempotency key canonicalises arguments, so a
     * reordering does not read as a different call.
     */
    private function isDuplicate(Run $run, ToolCall $call): bool
    {
        /** @var int $threshold */
        $threshold = config('pandora.tools.duplicate_threshold', 1);

        if ($threshold <= 0) {
            return false;
        }

        return ToolExecution::query()
            ->where('run_id', $run->getKey())
            ->where('idempotency_key', ToolExecution::idempotencyKey(
                (string) $run->getKey(),
                $call->name,
                $call->arguments,
            ))
            ->whereNot('status', ToolExecutionStatus::Denied->value)
            ->count() >= $threshold;
    }

    private function record(Run $run, ToolCall $call, ToolDecision $decision): ToolExecution
    {
        $arguments = $decision->arguments();

        /** @var ToolExecution $execution */
        $execution = ToolExecution::query()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->getKey(),
            'tool_name' => $call->name,
            'tool_version' => $decision->tool?->version() ?? '1.0',
            'tool_call_id' => $call->id,
            'arguments' => $arguments,
            'sanitized_arguments' => $this->redactor->redact($arguments),
            'arguments_modified' => $decision->wasModified(),
            'argument_diff' => $decision->wasModified()
                ? $this->redactor->redact($decision->diff?->toArray() ?? [])
                : null,
            'status' => $this->statusFor($decision)->value,
            'risk_level' => $decision->risk()->value,
            'decided_by' => $decision->layer->value,
            'decision_reason' => $decision->reason,
            // Keyed on what the MODEL asked for, so a duplicate is recognised
            // before a policy has a chance to rewrite it into something new.
            'idempotency_key' => ToolExecution::idempotencyKey(
                (string) $run->getKey(),
                $call->name,
                $call->arguments,
            ),
            'attempt' => 1,
        ]);

        return $execution;
    }

    private function statusFor(ToolDecision $decision): ToolExecutionStatus
    {
        return match (true) {
            $decision->isDenied() => ToolExecutionStatus::Denied,
            $decision->pausesRun() => ToolExecutionStatus::AwaitingApproval,
            default => ToolExecutionStatus::Pending,
        };
    }
}
