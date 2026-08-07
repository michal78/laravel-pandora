<?php

declare(strict_types=1);

namespace Pandora\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLogger;
use Pandora\Conversations\Conversation;
use Pandora\Conversations\Session;
use Pandora\Core\Actor\ActorManager;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Exceptions\PandoraException;
use Pandora\Messages\MessageWriter;
use Pandora\Providers\Data\ToolCall;
use Pandora\Realtime\RunBroadcaster;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\RunStepStatus;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\Run;
use Pandora\Runs\RunStateMachine;
use Pandora\Runs\RunStepRecorder;
use Pandora\Support\Redactor;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolCallCoordinator;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolExecution;
use Pandora\Tools\ToolGatekeeper;
use Pandora\Tools\ToolResult;

/**
 * Executes ONE authorized tool call.
 *
 * Three properties matter here, and each is load-bearing:
 *
 *  - **Idempotent.** A retried job finds a terminal execution row and does
 *    nothing. A tool's side effect is applied once even when the queue
 *    delivers twice.
 *  - **Re-authorized.** The call is validated and authorized AGAIN at
 *    execution time. Between the decision and now, an approval may have been
 *    waited on for days, a permission may have been revoked, and the tool's
 *    own rules may no longer be satisfied.
 *  - **Fan-in.** The last call to finish, and only the last, dispatches the
 *    next continuation. Decided under a lock on the run row, so "exactly one"
 *    is true rather than probable.
 */
final class ExecuteToolCall implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesPandoraContext;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $executionId,
        public readonly ?string $tenantId = null,
        public readonly bool $synchronous = false,
        public readonly ?string $actorType = null,
        public readonly ?string $actorId = null,
    ) {
        $this->onQueue(self::queueName('tools'));
        $this->onConnection(self::queueConnection());
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('pandora.runs.backoff', [5, 15, 60]);

        return $backoff;
    }

    public function handle(
        ToolGatekeeper $gatekeeper,
        ToolCallCoordinator $coordinator,
        RunStepRecorder $steps,
        MessageWriter $messages,
        RunBroadcaster $broadcaster,
        AuditLogger $audit,
        Redactor $redactor,
        TenantManager $tenants,
        ActorManager $actors,
        ConnectionInterface $connection,
    ): void {
        $this->withPandoraContext($tenants, $actors, function () use (
            $gatekeeper, $coordinator, $steps, $messages, $broadcaster, $audit, $redactor, $actors, $connection
        ): void {
            /** @var ToolExecution|null $execution */
            $execution = ToolExecution::query()->find($this->executionId);

            if ($execution === null || $execution->isTerminal()) {
                // Already done, or done by a previous attempt. Either way this
                // job has nothing to add -- but the run may still be waiting on
                // the fan-in, so we do not return before checking it.
                if ($execution !== null) {
                    $this->fanIn($execution, $coordinator, $connection);
                }

                return;
            }

            /** @var Run|null $run */
            $run = Run::query()->find($execution->run_id);

            if ($run === null) {
                return;
            }

            if ($run->state->isTerminal()) {
                $this->close($execution, ToolExecutionStatus::Cancelled, 'The run ended before this call ran.');

                return;
            }

            /** @var Agent $agent */
            $agent = Agent::query()->findOrFail($run->agent_id);
            /** @var Session $session */
            $session = Session::query()->findOrFail($run->session_id);

            $context = new ToolContext(
                run: $run,
                agent: $agent,
                session: $session,
                actor: $actors->current(),
                toolCallId: $execution->tool_call_id,
                attempt: $execution->attempt,
            );

            $execution->forceFill([
                'status' => ToolExecutionStatus::Running->value,
                'started_at' => now(),
            ])->save();

            $result = $this->execute($execution, $context, $gatekeeper, $steps, $run);

            // A delegation has not finished; it has started something else.
            // The call stays OPEN and no result is written, so the fan-in below
            // finds work outstanding and the parent stays in
            // `waiting_for_tool` holding no job. The child's terminal state is
            // what eventually closes this row -- see DelegationCompleter.
            if ($result->awaitsDelegate()) {
                $this->awaitDelegate($execution, $result, $steps, $run, $audit);

                return;
            }

            $this->persist($execution, $result, $redactor);
            $this->writeResultMessage($run, $execution, $result, $messages, $broadcaster);

            $steps->record(
                $run,
                $result->ok ? RunStepType::ToolResult : RunStepType::ToolExecution,
                $result->ok ? RunStepStatus::Succeeded : RunStepStatus::Failed,
                [
                    'tool' => $execution->tool_name,
                    'tool_call_id' => $execution->tool_call_id,
                    'status' => $execution->status->value,
                    'result' => $execution->sanitized_result,
                ],
                label: $execution->tool_name,
                durationMs: $execution->duration_ms,
                errorClass: $execution->error_class,
                errorMessage: $execution->error_message,
            );

            $audit->record(
                action: $result->ok ? 'tool.executed' : 'tool.failed',
                targetType: ToolExecution::class,
                targetId: (string) $execution->getKey(),
                runId: (string) $run->getKey(),
                severity: $result->ok ? 'info' : 'warning',
                metadata: [
                    'tool' => $execution->tool_name,
                    'risk' => $execution->risk_level->value,
                    'duration_ms' => $execution->duration_ms,
                    'arguments' => $execution->sanitized_arguments,
                ],
            );

            if ($result->awaitsUser) {
                $this->awaitUser($run, $result, $messages, $broadcaster, $connection);

                return;
            }

            $this->fanIn($execution, $coordinator, $connection);
        });
    }

    /**
     * Leave the call open while a child run answers it.
     *
     * Nothing here transitions the parent. It is already in `waiting_for_tool`,
     * which is precisely the state that means "a call is outstanding", and a
     * delegation is the one tool for which that remains true after the tool
     * returns. Marking the execution `running` with a started timestamp is
     * accurate rather than cosmetic: work IS in progress, in another run.
     */
    private function awaitDelegate(
        ToolExecution $execution,
        ToolResult $result,
        RunStepRecorder $steps,
        Run $run,
        AuditLogger $audit,
    ): void {
        // The child may already have finished -- instantly, on a synchronous
        // queue -- and `DelegationCompleter` may already have closed this row
        // and continued the parent. Writing to it now would reopen a call that
        // has been answered. The row was marked outstanding before the child
        // was dispatched, so there is nothing left here to do but the trace.
        $execution->refresh();

        if (! $execution->status->isTerminal()) {
            $execution->forceFill([
                'status' => ToolExecutionStatus::Running->value,
                'decision_reason' => $result->content,
            ])->save();
        }

        $steps->record(
            $run,
            RunStepType::ToolExecution,
            RunStepStatus::Started,
            [
                'tool' => $execution->tool_name,
                'tool_call_id' => $execution->tool_call_id,
                'child_run_id' => $result->delegatedRunId,
            ],
            label: $result->content,
        );

        $audit->record(
            action: 'tool.executed',
            targetType: ToolExecution::class,
            targetId: (string) $execution->getKey(),
            runId: (string) $run->getKey(),
            metadata: [
                'tool' => $execution->tool_name,
                'child_run_id' => $result->delegatedRunId,
                'awaiting_delegate' => true,
            ],
        );
    }

    /**
     * Park the run for the person it is acting for.
     *
     * Same shape as an approval pause and for the same reason: no job in
     * flight, nothing consumed, and a question sitting in the conversation
     * where the user will actually see it.
     */
    private function awaitUser(
        Run $run,
        ToolResult $result,
        MessageWriter $messages,
        RunBroadcaster $broadcaster,
        ConnectionInterface $connection,
    ): void {
        if ($run->conversation_id !== null) {
            /** @var Conversation|null $conversation */
            $conversation = Conversation::query()->find($run->conversation_id);

            if ($conversation !== null) {
                $message = $messages->question($conversation, $run, $result->content);
                $broadcaster->messageCreated($message, $run->correlation_id);
            }
        }

        $states = app(RunStateMachine::class);

        $connection->transaction(function () use ($run, $states, $broadcaster): void {
            /** @var Run $fresh */
            $fresh = Run::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($fresh->state->isTerminal() || $fresh->state === RunState::WaitingForUser) {
                return;
            }

            $previous = $fresh->state;
            $fresh = $states->transition($fresh, RunState::WaitingForUser);
            $broadcaster->stateChanged($fresh, $previous);
        });
    }

    /**
     * Run the tool, having proved again that it may be run.
     */
    private function execute(
        ToolExecution $execution,
        ToolContext $context,
        ToolGatekeeper $gatekeeper,
        RunStepRecorder $steps,
        Run $run,
    ): ToolResult {
        // Re-authorization. An approved call is not thereby a permitted one:
        // the gates are consulted now, against the state of the world now.
        $decision = $gatekeeper->evaluate(
            new ToolCall(
                id: $execution->tool_call_id,
                name: $execution->tool_name,
                arguments: $execution->arguments ?? [],
            ),
            $context,
        );

        if ($decision->isDenied()) {
            $steps->record(
                $run,
                RunStepType::ToolExecution,
                RunStepStatus::Failed,
                ['tool' => $execution->tool_name, 'revoked' => true] + $decision->toTrace(),
                label: 'Authorization changed since this call was decided',
            );

            $execution->forceFill(['status' => ToolExecutionStatus::Denied->value])->save();

            return ToolResult::failure($decision->modelMessage());
        }

        $tool = $decision->tool;
        $input = $decision->input;

        if ($tool === null || $input === null) {
            return ToolResult::failure('The tool is no longer available.');
        }

        try {
            return $tool->handle($input, $context);
        } catch (PandoraException $e) {
            $execution->forceFill([
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ])->save();

            return ToolResult::failure($e->userMessage());
        }
    }

    private function persist(ToolExecution $execution, ToolResult $result, Redactor $redactor): void
    {
        $startedAt = $execution->started_at;

        $execution->forceFill([
            'status' => $execution->status === ToolExecutionStatus::Denied
                ? ToolExecutionStatus::Denied->value
                : ($result->ok ? ToolExecutionStatus::Succeeded->value : ToolExecutionStatus::Failed->value),
            'result' => $result->jsonSerialize(),
            'sanitized_result' => $redactor->redact($result->jsonSerialize()),
            'finished_at' => now(),
            'duration_ms' => $startedAt === null ? null : (int) $startedAt->diffInMilliseconds(now()),
        ])->save();
    }

    /**
     * The result the model will read on its next turn.
     */
    private function writeResultMessage(
        Run $run,
        ToolExecution $execution,
        ToolResult $result,
        MessageWriter $messages,
        RunBroadcaster $broadcaster,
    ): void {
        if ($run->conversation_id === null) {
            return;
        }

        /** @var Conversation|null $conversation */
        $conversation = Conversation::query()->find($run->conversation_id);

        if ($conversation === null) {
            return;
        }

        $message = $messages->toolResult(
            $conversation,
            $run,
            $execution->tool_call_id,
            $result->content,
            $execution->tool_name,
            failed: ! $result->ok,
        );

        $broadcaster->messageCreated($message, $run->correlation_id);
    }

    private function close(ToolExecution $execution, ToolExecutionStatus $status, string $reason): void
    {
        $execution->forceFill([
            'status' => $status->value,
            'decision_reason' => $reason,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * The last call to finish dispatches the next continuation.
     *
     * The run row is locked while the count is taken, so two tool jobs
     * finishing at the same instant cannot both read "none left" and both
     * dispatch.
     */
    private function fanIn(
        ToolExecution $execution,
        ToolCallCoordinator $coordinator,
        ConnectionInterface $connection,
    ): void {
        $shouldContinue = $connection->transaction(function () use ($execution, $coordinator): bool {
            /** @var Run|null $run */
            $run = Run::query()->lockForUpdate()->find($execution->run_id);

            if ($run === null || $run->state->isTerminal()) {
                return false;
            }

            if ($coordinator->openCount($run) > 0) {
                return false;
            }

            // A run still waiting on a human keeps waiting: the approved calls
            // finishing does not resolve the ones nobody has decided yet.
            return $run->state === RunState::WaitingForTool;
        });

        if ($shouldContinue) {
            ContinueAgentRun::dispatch(
                $execution->run_id,
                $this->tenantId,
                $this->actorType,
                $this->actorId,
                $this->synchronous,
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        /** @var ToolExecution|null $execution */
        $execution = ToolExecution::query()->find($this->executionId);

        if ($execution === null || $execution->isTerminal()) {
            return;
        }

        $execution->forceFill([
            'status' => ToolExecutionStatus::Failed->value,
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ])->save();

        /** @var Run|null $run */
        $run = Run::query()->find($execution->run_id);

        if ($run !== null && ! $run->state->isTerminal()) {
            app(RunFailer::class)->fail($run, $exception);
        }
    }
}
