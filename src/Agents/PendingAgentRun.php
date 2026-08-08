<?php

declare(strict_types=1);

namespace Pandora\Agents;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Pandora\Conversations\Conversation;
use Pandora\Conversations\ConversationManager;
use Pandora\Conversations\SessionResolver;
use Pandora\Core\Actor\ActorContext;
use Pandora\Core\Actor\ActorManager;
use Pandora\Jobs\StartAgentRun;
use Pandora\Messages\MessageWriter;
use Pandora\Realtime\RunBroadcaster;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\TriggerType;
use Pandora\Runs\Run;
use Pandora\Runs\RunFactory;
use Pandora\Runs\RunStateMachine;

/**
 * The fluent builder behind `Pandora::agent('support')->...`.
 *
 * Mutable and single-use by design -- it is a request builder, not a value
 * object, and immutability here would cost readability for no safety gain
 * since it never escapes the call site.
 *
 * `dispatch()` queues and returns immediately; `run()` executes on the current
 * queue connection and waits. Web requests should always use `dispatch()`.
 */
final class PendingAgentRun
{
    private ?ActorContext $actor = null;

    private ?Conversation $conversation = null;

    private TriggerType $trigger = TriggerType::Application;

    private bool $stream = false;

    private ?string $queue = null;

    private ?string $idempotencyKey = null;

    private ?string $channel = null;

    private ?string $participantId = null;

    /** @var array<string, mixed> */
    private array $context = [];

    private ?string $providerOverride = null;

    private ?string $modelOverride = null;

    public function __construct(
        private readonly Agent $agent,
        private readonly RunFactory $runs,
        private readonly SessionResolver $sessions,
        private readonly ConversationManager $conversations,
        private readonly MessageWriter $messages,
        private readonly RunStateMachine $states,
        private readonly RunBroadcaster $broadcaster,
        private readonly ActorManager $actors,
    ) {}

    public function agent(): Agent
    {
        return $this->agent;
    }

    public function forUser(Authorizable $user): self
    {
        $this->actor = ActorContext::forUser($user);

        return $this;
    }

    public function forActor(?ActorContext $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    /**
     * Act as a named system actor -- an automation or a webhook.
     *
     * System actors carry no Authorizable, so any tool whose authorization
     * depends on a user is denied rather than silently allowed.
     */
    public function asSystem(string $label = 'system'): self
    {
        $this->actor = ActorContext::system($label);

        return $this;
    }

    /**
     * Run on behalf of one participant on one channel.
     *
     * Both values enter the session isolation key, which is what stops two
     * people in one Slack channel sharing a context boundary (T3). The
     * participant identifier is Pandora's, not the channel's -- it carries the
     * link epoch, so a re-linked handle starts a new boundary rather than
     * inheriting the previous holder's history.
     */
    public function viaChannel(string $channel, ?string $participantId = null): self
    {
        $this->channel = $channel;
        $this->participantId = $participantId;

        return $this;
    }

    public function inConversation(Conversation $conversation): self
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function triggeredBy(TriggerType $trigger): self
    {
        $this->trigger = $trigger;

        return $this;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    public function using(string $provider, ?string $model = null): self
    {
        $this->providerOverride = $provider;
        $this->modelOverride = $model;

        return $this;
    }

    public function stream(bool $stream = true): self
    {
        $this->stream = $stream;

        return $this;
    }

    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Deduplicate this run. A repeat delivery with the same key returns the
     * run already created rather than starting a second one.
     */
    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /**
     * Queue the run and return immediately. The correct choice from a web
     * request: no PHP request is ever held open for an agent run.
     */
    public function dispatch(string $input): Run
    {
        $run = $this->prepare($input);

        $previous = $run->state;
        $run = $this->states->transition($run, RunState::Queued);
        $this->broadcaster->stateChanged($run, $previous);

        $job = new StartAgentRun(
            runId: (string) $run->getKey(),
            tenantId: $run->tenant_id,
            actorType: $run->actor_type,
            actorId: $run->actor_id,
        );

        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }

        dispatch($job);

        return $run;
    }

    /**
     * Execute and wait.
     *
     * Runs the same queued jobs synchronously, so behaviour is identical to
     * `dispatch()` -- there is no second execution path to keep in step.
     * Intended for console commands, tests and background code, never for a
     * web request.
     */
    public function run(string $input): Run
    {
        $run = $this->dispatchSynchronously($input);

        return $run->refresh();
    }

    private function dispatchSynchronously(string $input): Run
    {
        $run = $this->prepare($input);

        $previous = $run->state;
        $run = $this->states->transition($run, RunState::Queued);
        $this->broadcaster->stateChanged($run, $previous);

        dispatch_sync(new StartAgentRun(
            runId: (string) $run->getKey(),
            tenantId: $run->tenant_id,
            actorType: $run->actor_type,
            actorId: $run->actor_id,
            synchronous: true,
        ));

        return $run;
    }

    /**
     * Create the session, conversation, user message and run.
     */
    private function prepare(string $input): Run
    {
        $actor = $this->actor ?? $this->actors->current();

        $conversation = $this->conversation;

        // A run with no conversation still needs a session, because the
        // session -- not the conversation -- is the isolation boundary.
        $session = $this->sessions->resolve(
            agent: $this->agent,
            actor: $actor,
            conversation: $conversation,
            channel: $this->channel ?? $conversation?->channel ?? 'web',
            participantId: $this->participantId,
            origin: $this->trigger->value,
        );

        if ($conversation !== null) {
            $this->conversations->titleFromMessage($conversation, $input);

            $this->messages->userMessage(
                conversation: $conversation,
                sessionId: (string) $session->getKey(),
                content: $input,
                authorType: $actor?->type,
                authorId: $actor?->id,
            );
        }

        $run = $this->runs->create(
            agent: $this->agent,
            session: $session,
            conversation: $conversation,
            actor: $actor,
            trigger: $this->trigger,
            input: $input,
            idempotencyKey: $this->idempotencyKey,
        );

        $overrides = array_filter([
            'provider_key' => $this->providerOverride,
            'model_key' => $this->modelOverride,
        ]);

        $metadata = array_filter([
            'context' => $this->context === [] ? null : $this->context,
            'stream' => $this->stream,
        ], static fn (mixed $v): bool => $v !== null);

        $run->forceFill($overrides + ['metadata' => $metadata])->save();

        return $run;
    }
}
