<?php

declare(strict_types=1);

namespace Pandora\Pandora\Runs;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Pandora\Pandora\Exceptions\InvalidRunTransition;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Events\RunStateChanged;
use Pandora\Pandora\Support\Redactor;

/**
 * The only component permitted to change a run's state.
 *
 * Transitions are validated, performed inside a transaction with the row
 * locked, and announced. An illegal transition THROWS rather than no-ops: a
 * silently ignored transition leaves a run wedged in a state nobody can
 * explain later.
 */
final class RunStateMachine
{
    /**
     * The complete transition table. Anything absent is illegal.
     *
     * @var array<string, list<RunState>>
     */
    private const TRANSITIONS = [
        'pending' => [RunState::Queued, RunState::Cancelled, RunState::Failed],
        'queued' => [RunState::Starting, RunState::Cancelling, RunState::Cancelled, RunState::Failed],
        'starting' => [RunState::Running, RunState::Failed, RunState::Cancelling, RunState::TimedOut],
        'running' => [
            RunState::WaitingForTool, RunState::WaitingForApproval, RunState::WaitingForUser,
            RunState::Paused, RunState::Completed, RunState::Failed, RunState::TimedOut,
            RunState::Cancelling, RunState::Running,
        ],
        'waiting_for_tool' => [
            // WaitingForUser is reachable because a tool may ASK something --
            // AskUser is a tool like any other, and its answer comes from a
            // person rather than from code.
            RunState::Running, RunState::WaitingForApproval, RunState::WaitingForUser,
            RunState::Failed, RunState::TimedOut, RunState::Cancelling,
        ],
        'waiting_for_approval' => [
            RunState::Running, RunState::WaitingForTool, RunState::Cancelled,
            RunState::Cancelling, RunState::Failed, RunState::TimedOut,
        ],
        'waiting_for_user' => [RunState::Running, RunState::Cancelled, RunState::Cancelling, RunState::TimedOut],
        'paused' => [RunState::Running, RunState::Cancelled, RunState::Cancelling],
        'cancelling' => [RunState::Cancelled, RunState::Failed],
        // Terminal states transition nowhere.
        'completed' => [],
        'failed' => [],
        'cancelled' => [],
        'timed_out' => [],
    ];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Dispatcher $events,
        private readonly Redactor $redactor,
    ) {}

    public function canTransition(RunState $from, RunState $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /**
     * Transition a run, applying any additional attributes atomically.
     *
     * @param array<string, mixed> $attributes
     */
    public function transition(Run $run, RunState $to, array $attributes = []): Run
    {
        return $this->connection->transaction(function () use ($run, $to, $attributes): Run {
            /** @var Run $fresh */
            $fresh = Run::query()->lockForUpdate()->findOrFail($run->getKey());

            $from = $fresh->state;

            if ($from === $to && $to !== RunState::Running) {
                return $fresh;
            }

            if ($from->isTerminal()) {
                throw InvalidRunTransition::terminal($from, (string) $fresh->getKey());
            }

            if (! $this->canTransition($from, $to)) {
                throw InvalidRunTransition::between($from, $to, (string) $fresh->getKey());
            }

            $fresh->fill($this->safe($attributes) + $this->timestampsFor($to));
            $fresh->state = $to;
            $fresh->save();

            // Refresh the caller's instance so it cannot act on stale state.
            $run->setRawAttributes($fresh->getAttributes(), true);

            $this->events->dispatch(new RunStateChanged($fresh, $from, $to));

            return $fresh;
        });
    }

    /**
     * A terminal error message is built from a PROVIDER's response, and
     * providers echo credentials back in their error text -- OpenAI does
     * exactly that on a 401. Redacting here rather than at every call site
     * means there is no path by which one reaches the runs table.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function safe(array $attributes): array
    {
        if (is_string($attributes['error_message'] ?? null)) {
            $attributes['error_message'] = $this->redactor->redactText($attributes['error_message']);
        }

        if (is_string($attributes['output'] ?? null)) {
            $attributes['output'] = $this->redactor->redactText($attributes['output']);
        }

        return $attributes;
    }

    /**
     * Timestamps implied by entering a state, so callers cannot forget them.
     *
     * @return array<string, mixed>
     */
    private function timestampsFor(RunState $state): array
    {
        return match (true) {
            $state === RunState::Queued => ['queued_at' => now()],
            $state === RunState::Starting => ['started_at' => now()],
            $state->isTerminal() => ['finished_at' => now(), 'owner_token' => null, 'owner_expires_at' => null],
            default => [],
        };
    }
}
