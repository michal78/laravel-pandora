<?php

declare(strict_types=1);

namespace Pandora\Runs;

use Pandora\Audit\AuditLogger;
use Pandora\Realtime\RunBroadcaster;
use Pandora\Runs\Enums\RunState;

/**
 * Requests cancellation and finalises it.
 *
 * Cancellation is cooperative: the flag is set, jobs observe it at their next
 * safe point, and in-flight work is allowed to finish rather than being torn
 * down mid-write. A run with no job in flight -- pending, waiting, paused --
 * can be finalised immediately, because there is nothing to wait for.
 */
final class RunCanceller
{
    public function __construct(
        private readonly RunStateMachine $states,
        private readonly RunBroadcaster $broadcaster,
        private readonly AuditLogger $audit,
    ) {}

    public function cancel(Run $run, ?string $reason = null): Run
    {
        if ($run->state->isTerminal()) {
            return $run;
        }

        $previous = $run->state;

        $run->forceFill(['cancel_requested_at' => now()])->save();

        // Children stop FIRST, and before either branch below returns.
        //
        // The order was the bug this fixes: cancelling children used to happen
        // only on the draining path, so a parent parked in `waiting_for_tool`
        // -- which is exactly the state a delegating parent is in -- finalised
        // immediately and left its child running. The child would go on
        // spending the tree's budget on behalf of a run that no longer wanted
        // the answer, and nothing would ever collect it.
        //
        // Downward only, and never upward. A cancelled delegate must not kill
        // the conversation that asked for it: the parent asked a question,
        // somebody withdrew the question, and the parent is still entitled to
        // carry on and say so.
        $this->cancelChildren($run);

        // Nothing is executing, so there is nothing to drain.
        if ($this->canFinaliseImmediately($run->state)) {
            $run = $this->states->transition($run, RunState::Cancelled, [
                'error_message' => $reason,
            ]);

            $this->broadcaster->stateChanged($run, $previous);

            return $run;
        }

        $run = $this->states->transition($run, RunState::Cancelling);

        $this->broadcaster->stateChanged($run, $previous);

        return $run;
    }

    /**
     * Cancel this run's children, transitively.
     *
     * Recursion is through `cancel()` rather than through a flattened id list,
     * so each descendant gets the same treatment as a directly cancelled run:
     * its own state decides whether it finalises now or drains, and its own
     * children are reached the same way. A depth-bounded tree makes the
     * recursion bounded too.
     */
    private function cancelChildren(Run $run): void
    {
        foreach ($run->children()->get() as $child) {
            if ($child->state->isTerminal()) {
                continue;
            }

            $this->cancel($child, 'Parent run cancelled.');

            $this->audit->record(
                action: 'run.cancellation_propagated',
                targetType: Run::class,
                targetId: (string) $child->getKey(),
                runId: (string) $run->getKey(),
                metadata: [
                    'parent_run_id' => (string) $run->getKey(),
                    'delegation_depth' => $child->delegation_depth,
                ],
            );
        }
    }

    /**
     * Finalise a run that has finished draining.
     */
    public function finalise(Run $run): Run
    {
        if ($run->state !== RunState::Cancelling) {
            return $run;
        }

        $previous = $run->state;
        $run = $this->states->transition($run, RunState::Cancelled);

        $this->broadcaster->stateChanged($run, $previous);

        return $run;
    }

    /**
     * States where no work is in progress, so there is nothing to drain.
     *
     * `queued` counts: a StartAgentRun job may be sitting in the queue, but it
     * has not begun, and it already returns immediately when it finds a
     * cancelled run. Draining would leave the run in `cancelling` until a
     * worker happened to pick up a job whose only action is to do nothing.
     */
    private function canFinaliseImmediately(RunState $state): bool
    {
        return $state === RunState::Pending
            || $state === RunState::Queued
            || $state->isWaiting();
    }
}
