<?php

declare(strict_types=1);

namespace Pandora\Pandora;

use Illuminate\Contracts\Container\Container;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Agents\AgentRegistry;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Agents\PendingAgentRun;
use Pandora\Pandora\Contracts\AgentDefinition;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Conversations\ConversationManager;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Providers\ProviderManager;
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
