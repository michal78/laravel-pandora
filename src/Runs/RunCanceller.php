<?php

declare(strict_types=1);

namespace Pandora\Runs;

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
    ) {}

    public function cancel(Run $run, ?string $reason = null): Run
    {
        if ($run->state->isTerminal()) {
            return $run;
        }

        $previous = $run->state;

        $run->forceFill(['cancel_requested_at' => now()])->save();

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

        // Children stop too; a cancelled parent has no use for their results.
        foreach ($run->children as $child) {
            $this->cancel($child, 'Parent run cancelled.');
        }

        return $run;
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
