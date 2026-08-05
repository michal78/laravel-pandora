<?php

declare(strict_types=1);

namespace Pandora\Pandora\Agents;

use Illuminate\Contracts\Container\Container;
use Pandora\Pandora\Conversations\ConversationManager;
use Pandora\Pandora\Conversations\SessionResolver;
use Pandora\Pandora\Core\Actor\ActorManager;
use Pandora\Pandora\Messages\MessageWriter;
use Pandora\Pandora\Realtime\RunBroadcaster;
use Pandora\Pandora\Runs\RunFactory;
use Pandora\Pandora\Runs\RunStateMachine;

/**
 * The injectable entry point to running agents.
 *
 * Prefer injecting this over using the facade inside domain code -- the facade
 * exists for ergonomics in application code, not as the internal API.
 *
 *   public function __construct(private readonly AgentRunner $agents) {}
 *
 *   $this->agents->agent('support')->forUser($user)->dispatch($message);
 */
final class AgentRunner
{
    public function __construct(
        private readonly Container $container,
        private readonly AgentRegistry $registry,
    ) {}

    public function agent(string|Agent $agent): PendingAgentRun
    {
        $model = $agent instanceof Agent ? $agent : $this->registry->get($agent);

        return new PendingAgentRun(
            agent: $model,
            runs: $this->container->make(RunFactory::class),
            sessions: $this->container->make(SessionResolver::class),
            conversations: $this->container->make(ConversationManager::class),
            messages: $this->container->make(MessageWriter::class),
            states: $this->container->make(RunStateMachine::class),
            broadcaster: $this->container->make(RunBroadcaster::class),
            actors: $this->container->make(ActorManager::class),
        );
    }

    public function registry(): AgentRegistry
    {
        return $this->registry;
    }
}
