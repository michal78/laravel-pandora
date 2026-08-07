<?php

declare(strict_types=1);

namespace Pandora\Delegation;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Pandora\Audit\AuditLogger;
use Pandora\Conversations\Conversation;
use Pandora\Jobs\ContinueAgentRun;
use Pandora\Messages\MessageWriter;
use Pandora\Realtime\RunBroadcaster;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\RunStepStatus;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\Events\RunStateChanged;
use Pandora\Runs\Run;
use Pandora\Runs\RunStepRecorder;
use Pandora\Support\Redactor;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolCallCoordinator;
use Pandora\Tools\ToolExecution;
use Pandora\Tools\ToolResult;

/**
 * Hands a finished child run's answer back to the parent that is waiting on it.
 *
 * This is the other half of `Delegator`. The parent has been sitting in
 * `waiting_for_tool` holding no job since the delegation started; its tool-call
 * row is still open; and nothing will ever close that row except this class
 * reacting to the child reaching a terminal state.
 *
 * Every terminal state is handled, not just success. A child that failed, timed
 * out, exhausted the tree's budget or was cancelled still owes the parent an
 * answer, because a parent that is never answered does not fail -- it waits
 * until its own deadline, which is the worst of both outcomes: slow AND
 * uninformative.
 */
final class DelegationCompleter
{
    public function __construct(
        private readonly ToolCallCoordinator $coordinator,
        private readonly RunStepRecorder $steps,
        private readonly MessageWriter $messages,
        private readonly RunBroadcaster $broadcaster,
        private readonly AuditLogger $audit,
        private readonly Redactor $redactor,
        private readonly Config $config,
        private readonly ConnectionInterface $connection,
    ) {}

    public function handle(RunStateChanged $event): void
    {
        if (! $event->to->isTerminal()) {
            return;
        }

        $child = $event->run;

        if ($child->delegated_tool_execution_id === null) {
            return;
        }

        /** @var ToolExecution|null $execution */
        $execution = ToolExecution::query()->find($child->delegated_tool_execution_id);

        // Already closed -- by a retry, or by the parent being cancelled first.
        // Doing nothing is the correct response to both.
        if ($execution === null || $execution->status->isTerminal()) {
            return;
        }

        /** @var Run|null $parent */
        $parent = Run::query()->find($child->parent_run_id);

        if ($parent === null) {
            return;
        }

        $result = $this->resultFor($child, $event->to);

        $this->close($execution, $result);
        $this->trace($parent, $child, $execution, $result, $event->to);

        // A terminal parent has nowhere to put this.
        if ($parent->state->isTerminal()) {
            return;
        }

        // A parent that is DRAINING gets no result message -- there is no model
        // turn left to read it -- but it does still get woken, because it is
        // waiting for exactly this call to close before it can finish
        // cancelling. Returning here instead is how a parent gets wedged in
        // `cancelling` forever: the child that would have released it has just
        // reported for the last time.
        if ($parent->state === RunState::Cancelling) {
            $this->resume($parent, finalisingCancellation: true);

            return;
        }

        $this->writeResultMessage($parent, $execution, $result);
        $this->resume($parent);
    }

    /**
     * The child's answer, as the parent's model will read it.
     *
     * Three things happen to it on the way, and all three are the same
     * precaution wearing different hats: a sub-agent that read a hostile page
     * returns a hostile string. It is REDACTED, because a child may have
     * touched credentials the parent's actor should not see echoed. It is
     * BOUNDED, because an unbounded child output is an unbounded parent prompt.
     * And it arrives as a tool result rather than as an instruction, which is
     * the door every untrusted string in this system comes through.
     *
     * The failure branches are deliberately specific. "The delegate failed" is
     * useless to a model deciding what to do next; "it ran out of the shared
     * budget" tells it that trying again, or delegating elsewhere, will not
     * work either.
     */
    private function resultFor(Run $child, RunState $terminal): ToolResult
    {
        $agentName = $child->agent?->name ?? 'The delegate';

        $data = [
            'child_run_id' => (string) $child->getKey(),
            'state' => $terminal->value,
            'delegation_depth' => $child->delegation_depth,
        ];

        if ($terminal === RunState::Completed) {
            return ToolResult::success($this->bounded((string) $child->output), $data);
        }

        if ($terminal === RunState::Cancelled) {
            return ToolResult::failure(
                sprintf('%s was cancelled before it finished.', $agentName),
                $data,
            );
        }

        // A budget breach ends a run as `timed_out` carrying BudgetExceeded --
        // the two are worth telling apart here even though the state does not,
        // because they call for completely different next moves.
        $exhausted = $child->error_class !== null
            && str_contains($child->error_class, 'BudgetExceeded');

        if ($terminal === RunState::TimedOut && $exhausted) {
            return ToolResult::failure(
                sprintf(
                    '%s stopped because the shared budget for this work is exhausted. '
                    .'Delegating again will not help. Report what you have.',
                    $agentName,
                ),
                $data + ['budget_exhausted' => true],
            );
        }

        if ($terminal === RunState::TimedOut) {
            return ToolResult::failure(
                sprintf('%s ran out of time before it finished.', $agentName),
                $data,
            );
        }

        return ToolResult::failure(
            sprintf(
                '%s could not complete the work: %s',
                $agentName,
                $this->bounded($child->error_message ?? 'no reason was recorded.'),
            ),
            $data,
        );
    }

    /**
     * Truncate to the configured ceiling, and SAY that it was truncated.
     *
     * Silent truncation is worse than the length it prevents: a model handed a
     * sentence that stops mid-clause will confabulate the rest rather than
     * notice, and it has no way to tell a short answer from a cut one.
     */
    private function bounded(string $content): string
    {
        /** @var int $limit */
        $limit = $this->config->get('pandora.delegation.max_result_length', 8000);

        $content = $this->redactor->redactText(trim($content));

        if ($content === '') {
            return 'The delegate finished without producing an answer.';
        }

        if (mb_strlen($content) <= $limit) {
            return $content;
        }

        return mb_substr($content, 0, $limit)
            ."\n\n[This answer was truncated at {$limit} characters.]";
    }

    private function close(ToolExecution $execution, ToolResult $result): void
    {
        $startedAt = $execution->started_at;

        $execution->forceFill([
            'status' => $result->ok
                ? ToolExecutionStatus::Succeeded->value
                : ToolExecutionStatus::Failed->value,
            'result' => $result->jsonSerialize(),
            'sanitized_result' => $this->redactor->redact($result->jsonSerialize()),
            'finished_at' => now(),
            'duration_ms' => $startedAt === null ? null : (int) $startedAt->diffInMilliseconds(now()),
        ])->save();
    }

    private function trace(
        Run $parent,
        Run $child,
        ToolExecution $execution,
        ToolResult $result,
        RunState $terminal,
    ): void {
        $this->steps->record(
            $parent,
            RunStepType::Delegation,
            $result->ok ? RunStepStatus::Succeeded : RunStepStatus::Failed,
            [
                'child_run_id' => (string) $child->getKey(),
                'child_agent' => $child->agent?->slug,
                'state' => $terminal->value,
                'result' => $execution->sanitized_result,
            ],
            label: 'Delegation to '.($child->agent?->name ?? 'another agent').' finished',
            durationMs: $child->durationMs(),
        );

        $this->audit->record(
            action: 'delegation.completed',
            targetType: Run::class,
            targetId: (string) $child->getKey(),
            runId: (string) $parent->getKey(),
            severity: $result->ok ? 'info' : 'warning',
            metadata: [
                'parent_run_id' => (string) $parent->getKey(),
                'child_agent' => $child->agent?->slug,
                'state' => $terminal->value,
                'input_tokens' => $child->input_tokens,
                'output_tokens' => $child->output_tokens,
                'cost_minor' => $child->cost_minor,
            ],
        );
    }

    private function writeResultMessage(Run $parent, ToolExecution $execution, ToolResult $result): void
    {
        if ($parent->conversation_id === null) {
            return;
        }

        /** @var Conversation|null $conversation */
        $conversation = Conversation::query()->find($parent->conversation_id);

        if ($conversation === null) {
            return;
        }

        $message = $this->messages->toolResult(
            $conversation,
            $parent,
            $execution->tool_call_id,
            $result->content,
            $execution->tool_name,
            failed: ! $result->ok,
        );

        $this->broadcaster->messageCreated($message, $parent->correlation_id);
    }

    /**
     * Wake the parent, if this was the last call it was waiting on.
     *
     * The same fan-in rule `ExecuteToolCall` uses, under the same lock, for the
     * same reason: a parent that delegated and also called two other tools must
     * continue exactly once, whichever of the three finishes last.
     */
    private function resume(Run $parent, bool $finalisingCancellation = false): void
    {
        $shouldContinue = $this->connection->transaction(
            function () use ($parent, $finalisingCancellation): bool {
                /** @var Run|null $fresh */
                $fresh = Run::query()->lockForUpdate()->find($parent->getKey());

                if ($fresh === null || $fresh->state->isTerminal()) {
                    return false;
                }

                if ($this->coordinator->openCount($fresh) > 0) {
                    return false;
                }

                return $finalisingCancellation
                    ? $fresh->state === RunState::Cancelling
                    : $fresh->state === RunState::WaitingForTool;
            },
        );

        if (! $shouldContinue) {
            return;
        }

        ContinueAgentRun::dispatch(
            (string) $parent->getKey(),
            $parent->tenant_id,
            $parent->actor_type,
            $parent->actor_id,
        );
    }
}
