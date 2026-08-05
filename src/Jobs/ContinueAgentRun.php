<?php

declare(strict_types=1);

namespace Pandora\Pandora\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Context\ContextBuilder;
use Pandora\Pandora\Context\ContextRequest;
use Pandora\Pandora\Contracts\ChatProvider;
use Pandora\Pandora\Contracts\StreamingProvider;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Conversations\Session;
use Pandora\Pandora\Core\Actor\ActorManager;
use Pandora\Pandora\Core\Tenancy\TenantManager;
use Pandora\Pandora\Exceptions\BudgetExceeded;
use Pandora\Pandora\Exceptions\PandoraException;
use Pandora\Pandora\Exceptions\Provider\ProviderException;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Messages\MessageWriter;
use Pandora\Pandora\Providers\Credentials\CredentialManager;
use Pandora\Pandora\Providers\Data\ChatMessage;
use Pandora\Pandora\Providers\Data\ChatRequest;
use Pandora\Pandora\Providers\Data\ChatResponse;
use Pandora\Pandora\Providers\Data\StreamDelta;
use Pandora\Pandora\Providers\Data\StreamDeltaType;
use Pandora\Pandora\Providers\ProviderManager;
use Pandora\Pandora\Realtime\RunBroadcaster;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Enums\RunStepStatus;
use Pandora\Pandora\Runs\Enums\RunStepType;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Runs\RunLock;
use Pandora\Pandora\Runs\RunStateMachine;
use Pandora\Pandora\Runs\RunStepRecorder;
use Pandora\Pandora\Tools\ToolCallCoordinator;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolGatekeeper;

/**
 * ONE iteration of the execution loop.
 *
 * Load state, do one bounded unit of work, append immutable steps, transition,
 * and either dispatch the next continuation or stop. Nothing required to
 * continue is held in PHP memory, so a worker crash costs at most this
 * iteration and the queue retries it.
 *
 * Phase 1 completes after a single model turn. Phase 2 adds the tool branch,
 * at which point this job dispatches ExecuteToolCall jobs and the last of them
 * dispatches the next continuation.
 *
 * See docs/architecture/execution-model.md.
 */
final class ContinueAgentRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesPandoraContext;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Work to hand off once this iteration has released the run lock.
     *
     * A tool job that starts while we still hold the lock cannot fan back in
     * to a continuation -- it would find the run locked and quietly do
     * nothing, stalling the run. On a `sync` queue connection that is not a
     * race but a certainty.
     *
     * @var list<\Closure(): void>
     */
    private array $deferred = [];

    public function __construct(
        public readonly string $runId,
        public readonly ?string $tenantId = null,
        public readonly ?string $actorType = null,
        public readonly ?string $actorId = null,
        /** @see StartAgentRun::__construct() -- carried through every continuation. */
        public readonly bool $synchronous = false,
    ) {
        $this->onQueue(self::queueName('agents'));
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
        RunLock $locks,
        RunStateMachine $states,
        RunBroadcaster $broadcaster,
        ContextBuilder $context,
        ProviderManager $providers,
        MessageWriter $messages,
        RunStepRecorder $steps,
        AuditLogger $audit,
        TenantManager $tenants,
        ActorManager $actors,
        ToolCallCoordinator $tools,
        ToolGatekeeper $gatekeeper,
        CredentialManager $credentials,
    ): void {
        $this->withPandoraContext($tenants, $actors, function () use (
            $locks, $states, $broadcaster, $context, $providers, $messages, $steps, $audit,
            $actors, $tools, $gatekeeper, $credentials
        ): void {
            // 1. Take ownership. Another worker holding it means there is
            //    nothing for us to do -- not an error.
            $token = $locks->acquire($this->runId);

            if ($token === null) {
                return;
            }

            /** @var Run|null $run */
            $run = Run::query()->find($this->runId);

            if ($run === null) {
                return;
            }

            try {
                $this->iterate(
                    $run, $states, $broadcaster, $context, $providers, $messages,
                    $steps, $audit, $actors, $tools, $gatekeeper, $credentials,
                );
            } catch (PandoraException $e) {
                $this->failRun($run, $e, $states, $broadcaster, $messages, $steps, $audit);
            } finally {
                $locks->release($this->runId, $token);
            }

            foreach ($this->deferred as $handoff) {
                $handoff();
            }

            $this->deferred = [];
        });
    }

    private function iterate(
        Run $run,
        RunStateMachine $states,
        RunBroadcaster $broadcaster,
        ContextBuilder $context,
        ProviderManager $providers,
        MessageWriter $messages,
        RunStepRecorder $steps,
        AuditLogger $audit,
        ActorManager $actors,
        ToolCallCoordinator $tools,
        ToolGatekeeper $gatekeeper,
        CredentialManager $credentials,
    ): void {
        // 2. Assert the run may continue.
        if ($run->state->isTerminal()) {
            return;
        }

        if ($run->isCancelRequested()) {
            $this->completeCancellation($run, $states, $broadcaster, $messages);

            return;
        }

        if (! $run->state->isContinuable()) {
            return;
        }

        // A run resuming after its tools came back is running again, and says
        // so before it does anything else. Skipping this would leave the run
        // finishing from `waiting_for_tool`, which the state machine rightly
        // refuses, and would show a stale status in the UI for a whole turn.
        if ($run->state === RunState::WaitingForTool) {
            $previous = $run->state;
            $run = $states->transition($run, RunState::Running);
            $broadcaster->stateChanged($run, $previous);
        }

        /** @var Agent $agent */
        $agent = Agent::query()->findOrFail($run->agent_id);

        // 3. Budgets, checked BEFORE the expensive call -- a run that would
        //    exceed its budget never makes the request.
        $this->assertWithinBudget($run, $agent);

        /** @var Session $session */
        $session = Session::query()->findOrFail($run->session_id);

        $conversation = $run->conversation_id !== null
            ? Conversation::query()->find($run->conversation_id)
            : null;

        // 4. Build context.
        $built = $context->build(new ContextRequest(
            run: $run,
            agent: $agent,
            session: $session,
            tokenBudget: $agent->context_budget_tokens,
        ));

        $steps->record(
            $run,
            RunStepType::ContextRetrieval,
            RunStepStatus::Succeeded,
            $built->toTrace(),
            label: sprintf('%d sections, ~%d tokens', count($built->included), $built->estimatedTokens),
        );

        // 5. Add this turn's user input, which is not yet a stored message when
        //    the run was triggered from code rather than from chat.
        $chatMessages = $built->messages;

        if ($run->input !== null && $run->input !== '' && ! $this->inputAlreadyInContext($run, $built->messages)) {
            $chatMessages[] = ChatMessage::user($run->input);
        }

        // 6. Resolve provider and model.
        $providerKey = $run->provider_key ?? $providers->default();
        /** @var string $modelKey */
        $modelKey = $run->model_key ?? config('pandora.models.default', 'fake-model');

        $provider = $providers->chat($providerKey);

        // What this agent may ASK for. Advertising a tool grants nothing: the
        // request is judged in full when it arrives.
        $toolDefinitions = $gatekeeper->advertise(new ToolContext(
            run: $run,
            agent: $agent,
            session: $session,
            actor: $actors->current(),
            toolCallId: '',
        ));

        $request = new ChatRequest(
            model: $modelKey,
            messages: $chatMessages,
            options: $agent->provider_options ?? [],
            stream: $provider instanceof StreamingProvider,
            tools: $toolDefinitions,
        );

        $steps->record(
            $run,
            RunStepType::ModelRequest,
            RunStepStatus::Started,
            $request->toTrace(),
            label: "{$providerKey} / {$modelKey}",
        );

        $run->forceFill([
            'provider_key' => $providerKey,
            'model_key' => $modelKey,
            'iterations' => $run->iterations + 1,
        ])->save();

        // 7. The assistant message the run streams into. Created before the
        //    call so a reload during the request finds something to render.
        $assistantMessage = $conversation !== null
            ? $messages->assistantPlaceholder($conversation, $run)
            : null;

        if ($assistantMessage !== null) {
            $broadcaster->messageCreated($assistantMessage, $run->correlation_id);
        }

        // 8. Call the model.
        $startedAt = hrtime(true);

        try {
            // The agent is in scope for the duration of the call, and only
            // for its duration, so a per-agent credential resolves without
            // any part of the request carrying one.
            $response = $credentials->forAgent($agent->id, fn (): ChatResponse => $this->callProvider(
                $provider,
                $request,
                $run,
                $assistantMessage,
                $messages,
                $broadcaster,
            ));
        } catch (ProviderException $e) {
            if ($assistantMessage !== null) {
                $messages->fail($assistantMessage, $e->userMessage());
                $broadcaster->messageCompleted($run, $assistantMessage, failed: true);
            }

            throw $e;
        }

        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $steps->record(
            $run,
            RunStepType::ModelResponse,
            RunStepStatus::Succeeded,
            $response->toTrace(),
            rawMeta: $response->rawMeta,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
            durationMs: $durationMs,
        );

        $run->forceFill([
            'input_tokens' => $run->input_tokens + $response->usage->inputTokens,
            'output_tokens' => $run->output_tokens + $response->usage->outputTokens,
        ])->save();

        // 9. Finalise the assistant message.
        if ($assistantMessage !== null) {
            $messages->complete($assistantMessage, $response->content);
            $broadcaster->messageCompleted($run, $assistantMessage);
        }

        // 10. A cancellation that arrived while the model was responding: the
        //     work is done and recorded, so honour the request now.
        if ($run->refresh()->isCancelRequested()) {
            $this->completeCancellation($run, $states, $broadcaster, $messages);

            return;
        }

        // 11. The tool branch. A response requesting tools is not an answer:
        //     the calls are decided, recorded and handed off, and the run
        //     parks until they come back or a human decides.
        if ($response->toolCalls !== []) {
            $this->requestTools(
                $run,
                $agent,
                $session,
                $response,
                $assistantMessage,
                $states,
                $broadcaster,
                $messages,
                $tools,
                $actors,
            );

            return;
        }

        $steps->record(
            $run,
            RunStepType::FinalResponse,
            RunStepStatus::Succeeded,
            ['content_chars' => mb_strlen($response->content)],
        );

        $previous = $run->state;
        $run = $states->transition($run, RunState::Completed, ['output' => $response->content]);
        $broadcaster->stateChanged($run, $previous);

        $audit->record(
            action: 'run.completed',
            targetType: Run::class,
            targetId: (string) $run->getKey(),
            runId: (string) $run->getKey(),
            metadata: [
                'iterations' => $run->iterations,
                'input_tokens' => $run->input_tokens,
                'output_tokens' => $run->output_tokens,
                'duration_ms' => $run->durationMs(),
            ],
        );
    }

    /**
     * The model asked for tools.
     *
     * The assistant message keeps the calls it made, because every provider
     * that accepts a tool result also demands the request that produced it.
     * The run then parks: `waiting_for_approval` if a human owes a decision,
     * otherwise `waiting_for_tool` while the tool jobs run.
     */
    private function requestTools(
        Run $run,
        Agent $agent,
        Session $session,
        ChatResponse $response,
        ?Message $assistantMessage,
        RunStateMachine $states,
        RunBroadcaster $broadcaster,
        MessageWriter $messages,
        ToolCallCoordinator $tools,
        ActorManager $actors,
    ): void {
        if ($assistantMessage !== null) {
            $messages->recordToolCalls($assistantMessage, $response->toolCalls);
        }

        $actor = $actors->current();

        $executions = $tools->decide($run, $agent, $session, $actor, $response->toolCalls);

        $previous = $run->state;
        $run = $states->transition($run, $tools->nextState($executions));
        $broadcaster->stateChanged($run, $previous);

        // Handed off only after the transition is committed AND the run lock
        // is released, so a tool job that starts immediately can still fan
        // back in.
        $this->deferred[] = function () use ($tools, $run, $executions, $actor): void {
            $tools->dispatch($run, $executions, $actor, $this->synchronous);
        };
    }

    /**
     * Stream when the provider supports it, otherwise fall back to a single
     * call. Both paths produce the same ChatResponse.
     */
    private function callProvider(
        ChatProvider $provider,
        ChatRequest $request,
        Run $run,
        ?Message $assistantMessage,
        MessageWriter $messages,
        RunBroadcaster $broadcaster,
    ): ChatResponse {
        if (! $provider instanceof StreamingProvider || $assistantMessage === null) {
            return $provider->chat($request->withStreaming(false));
        }

        $pending = '';

        return $provider->stream($request, function (StreamDelta $delta) use (
            $assistantMessage, $messages, $broadcaster, $run, &$pending
        ): void {
            if ($delta->type !== StreamDeltaType::Text || $delta->text === '') {
                return;
            }

            $pending .= $delta->text;

            // Buffered: persisted state and broadcast state advance together,
            // so a mid-stream reload never sees less than the browser did.
            if ($messages->appendDelta($assistantMessage, $delta->text)) {
                $broadcaster->delta($run, $assistantMessage, $pending);
                $pending = '';
            }
        });
    }

    private function assertWithinBudget(Run $run, Agent $agent): void
    {
        if ($run->iterations >= $agent->max_iterations) {
            throw BudgetExceeded::iterations((string) $run->getKey(), $agent->max_iterations);
        }

        if ($run->tool_calls_count > $agent->max_tool_calls) {
            throw BudgetExceeded::toolCalls((string) $run->getKey(), $agent->max_tool_calls);
        }

        if ($run->hasExceededDeadline()) {
            throw BudgetExceeded::duration((string) $run->getKey(), $agent->max_duration_seconds);
        }

        if ($agent->token_budget !== null
            && ($run->input_tokens + $run->output_tokens) >= $agent->token_budget) {
            throw BudgetExceeded::tokens((string) $run->getKey(), (int) $agent->token_budget);
        }
    }

    /**
     * Avoid duplicating the triggering message when it is already stored and
     * therefore already present in the recent-messages section.
     *
     * @param list<ChatMessage> $contextMessages
     */
    private function inputAlreadyInContext(Run $run, array $contextMessages): bool
    {
        foreach (array_reverse($contextMessages) as $message) {
            if ($message->role->value === 'user' && $message->content === $run->input) {
                return true;
            }
        }

        return false;
    }

    private function completeCancellation(
        Run $run,
        RunStateMachine $states,
        RunBroadcaster $broadcaster,
        MessageWriter $messages,
    ): void {
        $previous = $run->state;

        if ($run->state !== RunState::Cancelling) {
            $run = $states->transition($run, RunState::Cancelling);
            $previous = RunState::Cancelling;
        }

        $run = $states->transition($run, RunState::Cancelled);
        $broadcaster->stateChanged($run, $previous);

        // Any partially-streamed assistant message is closed rather than left
        // permanently in a streaming state.
        foreach ($run->messages()->where('streaming_state', 'streaming')->get() as $message) {
            $messages->complete($message);
            $broadcaster->messageCompleted($run, $message);
        }
    }

    private function failRun(
        Run $run,
        PandoraException $exception,
        RunStateMachine $states,
        RunBroadcaster $broadcaster,
        MessageWriter $messages,
        RunStepRecorder $steps,
        AuditLogger $audit,
    ): void {
        $steps->record(
            $run,
            RunStepType::Error,
            RunStepStatus::Failed,
            ['error_code' => $exception->errorCode()],
            errorClass: $exception::class,
            errorMessage: $exception->getMessage(),
        );

        $previous = $run->state;

        $terminal = $exception instanceof BudgetExceeded
            ? RunState::TimedOut
            : RunState::Failed;

        $run = $states->transition($run, $terminal, [
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
        ]);

        foreach ($run->messages()->where('streaming_state', 'streaming')->get() as $message) {
            $messages->fail($message, $exception->userMessage());
            $broadcaster->messageCompleted($run, $message, failed: true);
        }

        $broadcaster->stateChanged($run, $previous, $exception->userMessage());

        $audit->record(
            action: 'run.failed',
            targetType: Run::class,
            targetId: (string) $run->getKey(),
            runId: (string) $run->getKey(),
            severity: 'error',
            metadata: ['error_class' => $exception::class, 'error_code' => $exception->errorCode()],
        );
    }

    public function failed(\Throwable $exception): void
    {
        /** @var Run|null $run */
        $run = Run::query()->find($this->runId);

        if ($run === null || $run->state->isTerminal()) {
            return;
        }

        app(RunFailer::class)->fail($run, $exception);
    }
}
