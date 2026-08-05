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
use Pandora\Pandora\Contracts\ModelRouter;
use Pandora\Pandora\Contracts\StreamingProvider;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Conversations\Session;
use Pandora\Pandora\Core\Actor\ActorManager;
use Pandora\Pandora\Core\Tenancy\TenantManager;
use Pandora\Pandora\Exceptions\BudgetExceeded;
use Pandora\Pandora\Exceptions\NoModelAvailable;
use Pandora\Pandora\Exceptions\PandoraException;
use Pandora\Pandora\Exceptions\Provider\ContextOverflow;
use Pandora\Pandora\Exceptions\Provider\ProviderException;
use Pandora\Pandora\Exceptions\Provider\ProviderRateLimited;
use Pandora\Pandora\Exceptions\Provider\ProviderTimeout;
use Pandora\Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Messages\MessageWriter;
use Pandora\Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Pandora\Providers\Credentials\CredentialManager;
use Pandora\Pandora\Providers\Data\ChatMessage;
use Pandora\Pandora\Providers\Data\ChatRequest;
use Pandora\Pandora\Providers\Data\ChatResponse;
use Pandora\Pandora\Providers\Data\StreamDelta;
use Pandora\Pandora\Providers\Data\StreamDeltaType;
use Pandora\Pandora\Providers\Data\ToolDefinition;
use Pandora\Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Pandora\Providers\ProviderManager;
use Pandora\Pandora\Providers\Routing\RoutingRequest;
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
use Pandora\Pandora\Usage\BudgetGuard;
use Pandora\Pandora\Usage\UsageRecorder;

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
        ModelRouter $router,
        ProviderHealthMonitor $health,
        UsageRecorder $usage,
        BudgetGuard $budgets,
    ): void {
        $this->withPandoraContext($tenants, $actors, function () use (
            $locks, $states, $broadcaster, $context, $providers, $messages, $steps, $audit,
            $actors, $tools, $gatekeeper, $credentials, $router, $health, $usage, $budgets
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
                    $router, $health, $usage, $budgets,
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
        ModelRouter $router,
        ProviderHealthMonitor $health,
        UsageRecorder $usage,
        BudgetGuard $budgets,
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
        //    exceed its budget never makes the request. The run's own limits
        //    first, then every wider scope.
        $this->assertWithinBudget($run, $agent);
        $budgets->assert($run, $agent);

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

        // 6. What this agent may ASK for. Advertising a tool grants nothing:
        //    the request is judged in full when it arrives.
        $toolDefinitions = $gatekeeper->advertise(new ToolContext(
            run: $run,
            agent: $agent,
            session: $session,
            actor: $actors->current(),
            toolCallId: '',
        ));

        $run->forceFill(['iterations' => $run->iterations + 1])->save();

        // 7. The assistant message the run streams into. Created before the
        //    call so a reload during the request finds something to render.
        $assistantMessage = $conversation !== null
            ? $messages->assistantPlaceholder($conversation, $run)
            : null;

        if ($assistantMessage !== null) {
            $broadcaster->messageCreated($assistantMessage, $run->correlation_id);
        }

        // 8. Route, and call -- walking the fallback chain when a failure is
        //    one another model could survive.
        $startedAt = hrtime(true);

        try {
            $response = $this->callWithFailover(
                new RoutingRequest(
                    agent: $agent,
                    runProvider: $run->provider_key,
                    runModel: $run->model_key,
                    conversationProvider: $conversation?->provider_override,
                    conversationModel: $conversation?->model_override,
                    tenantId: $run->tenant_id,
                ),
                $run,
                $agent,
                $chatMessages,
                $toolDefinitions,
                $assistantMessage,
                $router,
                $providers,
                $credentials,
                $health,
                $messages,
                $broadcaster,
                $steps,
                $audit,
            );
        } catch (PandoraException $e) {
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

        // One row per CALL, not per run: a run that failed over spent money at
        // two providers, and a single aggregated row would hide that in
        // exactly the situation where somebody is asking why the bill grew.
        $usage->record(
            $run,
            (string) $run->provider_key,
            (string) $run->model_key,
            $response->usage,
        );

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
     * Route, call, and on a survivable failure route again.
     *
     * The loop is the whole of failover. Every hop leaves two steps on the
     * trace -- where it went, and why the previous one did not work -- so a
     * run that quietly finished on the third model in the chain can be
     * explained months later without guessing.
     *
     * @param list<ChatMessage> $chatMessages
     * @param list<ToolDefinition> $toolDefinitions
     */
    private function callWithFailover(
        RoutingRequest $routing,
        Run $run,
        Agent $agent,
        array $chatMessages,
        array $toolDefinitions,
        ?Message $assistantMessage,
        ModelRouter $router,
        ProviderManager $providers,
        CredentialManager $credentials,
        ProviderHealthMonitor $health,
        MessageWriter $messages,
        RunBroadcaster $broadcaster,
        RunStepRecorder $steps,
        AuditLogger $audit,
    ): ChatResponse {
        /** @var list<string> $excluded */
        $excluded = [];
        $rateLimitAttempts = 0;
        $lastFailure = null;

        $maxRateLimitAttempts = (int) config('pandora.providers.retry.rate_limit_attempts', 2);

        while (true) {
            try {
                $decision = $router->resolve($routing->excluding($excluded));
            } catch (NoModelAvailable $e) {
                // The chain is exhausted. The operator needs the reason the
                // last real attempt failed, not "no model available" -- that
                // sends them hunting through four config files for a problem
                // that is nothing to do with configuration.
                throw $lastFailure ?? $e;
            }

            $steps->record(
                $run,
                RunStepType::ModelRouting,
                RunStepStatus::Succeeded,
                $decision->toTrace(),
                label: $decision->reference().' — '.$decision->source->label(),
            );

            $provider = $providers->chat($decision->providerKey);

            $request = new ChatRequest(
                model: $decision->modelKey,
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
                label: $decision->reference(),
            );

            $run->forceFill([
                'provider_key' => $decision->providerKey,
                'model_key' => $decision->modelKey,
            ])->save();

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

                // A working call is evidence too: it lets a degraded provider
                // recover without waiting for the next probe.
                $health->recordSuccess($decision->providerKey, $response->usage->durationMs);

                return $response;
            } catch (ProviderException $e) {
                $lastFailure = $e;

                $steps->record(
                    $run,
                    RunStepType::ModelRequest,
                    RunStepStatus::Failed,
                    ['provider' => $decision->providerKey, 'model' => $decision->modelKey],
                    label: $decision->reference(),
                    errorClass: $e::class,
                    errorMessage: $e->getMessage(),
                );

                if ($this->indicatesProviderTrouble($e)) {
                    $health->recordFailure($decision->providerKey, $e->getMessage());
                }

                // A rate limit is the one failure worth waiting out: the model
                // that was asked for is usually still the right one, and a
                // fallback chain that fires on every 429 spends the day
                // answering from the wrong model.
                if ($e instanceof ProviderRateLimited && $rateLimitAttempts < $maxRateLimitAttempts) {
                    $rateLimitAttempts++;
                    $this->waitOutRateLimit($e);

                    continue;
                }

                if (! $e->allowsFailover()) {
                    throw $e;
                }

                // A larger context is the only thing that can help here, so
                // the chain is narrowed rather than merely advanced.
                if ($e instanceof ContextOverflow) {
                    $routing = $routing->needingContext(
                        $this->contextLimitOf($decision->providerKey, $decision->modelKey),
                    );
                }

                $excluded[] = $decision->reference();

                $audit->record(
                    action: 'provider.failover',
                    targetType: Run::class,
                    targetId: (string) $run->getKey(),
                    runId: (string) $run->getKey(),
                    severity: 'warning',
                    metadata: [
                        'from' => $decision->reference(),
                        'reason' => $e->errorCode(),
                        'attempt' => count($excluded),
                    ],
                );
            }
        }
    }

    /**
     * Whether a failure says anything about the PROVIDER's health.
     *
     * A rejected request and an exhausted quota are facts about us, not about
     * the provider, and counting them towards degradation would take a
     * perfectly healthy provider out of every fallback chain.
     */
    private function indicatesProviderTrouble(ProviderException $exception): bool
    {
        return $exception instanceof ProviderUnavailable
            || $exception instanceof ProviderTimeout;
    }

    private function waitOutRateLimit(ProviderRateLimited $exception): void
    {
        /** @var int $configured */
        $configured = config('pandora.providers.retry.delay_ms', 500);

        // Honour Retry-After when the provider sent one, but never sleep a
        // worker for longer than the run's own patience.
        $delayMs = $exception->retryAfterSeconds !== null
            ? min($exception->retryAfterSeconds * 1000, 5_000)
            : $configured;

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function contextLimitOf(string $providerKey, string $modelKey): ?int
    {
        return app(ModelCatalog::class)->find($providerKey, $modelKey)?->context_limit;
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
