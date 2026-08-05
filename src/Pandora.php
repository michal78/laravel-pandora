<?php

declare(strict_types=1);

namespace Pandora\Pandora;

use Illuminate\Contracts\Container\Container;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Agents\AgentRegistry;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Agents\PendingAgentRun;
use Pandora\Pandora\Automation\AutomationScheduler;
use Pandora\Pandora\Automation\EventTriggerRegistry;
use Pandora\Pandora\Automation\PendingEventTrigger;
use Pandora\Pandora\Contracts\AgentDefinition;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Conversations\ConversationManager;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Exceptions\InvalidRunTransition;
use Pandora\Pandora\Jobs\ResumeRunWithUserReply;
use Pandora\Pandora\Messages\MessageWriter;
use Pandora\Pandora\Providers\ProviderManager;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Runs\RunCanceller;

/**
 * The ergonomic public entry point behind the `Pandora` facade.
 *
 * Deliberately thin: it holds no logic of its own and delegates to the module
 * services. This is what keeps the codebase free of a `PandoraManager` god
 * object while still giving application code one obvious place to start.
 */
final class Pandora
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Begin a run.
     *
     *   Pandora::agent('support')->forUser($user)->dispatch('Help me.');
     */
    public function agent(string|Agent $agent): PendingAgentRun
    {
        return $this->container->make(AgentRunner::class)->agent($agent);
    }

    /**
     * Register class-based agent definitions.
     *
     * @param class-string<AgentDefinition>|list<class-string<AgentDefinition>> $definitions
     */
    public function define(string|array $definitions): AgentRegistry
    {
        $registry = $this->agents();

        return is_array($definitions)
            ? $registry->defineMany($definitions)
            : $registry->define($definitions);
    }

    public function agents(): AgentRegistry
    {
        return $this->container->make(AgentRegistry::class);
    }

    /**
     * Start a run when a Laravel event fires.
     *
     *   Pandora::on(OrderShipped::class)
     *       ->when(fn (OrderShipped $e) => $e->order->isInternational())
     *       ->map(fn (OrderShipped $e) => ['reference' => $e->order->reference])
     *       ->run('logistics');
     *
     * The counterpart to a database automation of trigger type `event`. Both
     * exist because both are wanted: code for what belongs in version control
     * and gets reviewed, rows for what an operator adds at 3am.
     *
     * Declare these in a service provider's `boot()`. Nothing is registered
     * until `run()` is called on the returned builder.
     */
    public function on(string $eventClass): PendingEventTrigger
    {
        return $this->container->make(EventTriggerRegistry::class)->on($eventClass);
    }

    public function automations(): AutomationScheduler
    {
        return $this->container->make(AutomationScheduler::class);
    }

    public function providers(): ProviderManager
    {
        return $this->container->make(ProviderManager::class);
    }

    public function conversations(): ConversationManager
    {
        return $this->container->make(ConversationManager::class);
    }

    public function startConversation(
        string|Agent $agent,
        ?ActorContext $actor = null,
        ?string $title = null,
        string $channel = 'web',
    ): Conversation {
        $model = $agent instanceof Agent ? $agent : $this->agents()->get($agent);

        return $this->conversations()->start($model, $actor, $title, $channel);
    }

    /**
     * Answer a run that asked the user something.
     *
     * The answer becomes an ordinary message in the conversation, so the next
     * iteration picks it up through the usual context pipeline -- there is no
     * second path by which a reply reaches the model.
     */
    public function reply(Run|string $run, string $answer, bool $synchronous = false): Run
    {
        $model = $run instanceof Run ? $run : Run::query()->findOrFail($run);

        if ($model->state !== RunState::WaitingForUser) {
            throw InvalidRunTransition::notAwaitingUser((string) $model->getKey(), $model->state);
        }

        if ($model->conversation_id !== null) {
            /** @var Conversation $conversation */
            $conversation = Conversation::query()->findOrFail($model->conversation_id);

            $this->container->make(MessageWriter::class)->userMessage(
                $conversation,
                (string) $model->session_id,
                $answer,
                $model->actor_type,
                $model->actor_id,
            );
        }

        $job = new ResumeRunWithUserReply(
            runId: (string) $model->getKey(),
            tenantId: $model->tenant_id,
            actorType: $model->actor_type,
            actorId: $model->actor_id,
            synchronous: $synchronous,
        );

        $synchronous ? dispatch_sync($job) : dispatch($job);

        return $model->refresh();
    }

    public function cancel(Run|string $run, ?string $reason = null): Run
    {
        $model = $run instanceof Run ? $run : Run::query()->findOrFail($run);

        return $this->container->make(RunCanceller::class)->cancel($model, $reason);
    }

    public function version(): string
    {
        return '0.1.0-dev';
    }
}
