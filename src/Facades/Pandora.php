<?php

declare(strict_types=1);

namespace Pandora\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Pandora\Agents\PendingAgentRun agent(string|\Pandora\Agents\Agent $agent)
 * @method static \Pandora\Agents\AgentRegistry define(string|list<class-string<\Pandora\Contracts\AgentDefinition>> $definitions)
 * @method static \Pandora\Agents\AgentRegistry agents()
 * @method static \Pandora\Automation\PendingEventTrigger on(string $eventClass)
 * @method static \Pandora\Automation\AutomationScheduler automations()
 * @method static \Pandora\Providers\ProviderManager providers()
 * @method static \Pandora\Conversations\ConversationManager conversations()
 * @method static \Pandora\Conversations\Conversation startConversation(string|\Pandora\Agents\Agent $agent, ?\Pandora\Core\Actor\ActorContext $actor = null, ?string $title = null, string $channel = 'web')
 * @method static \Pandora\Runs\Run cancel(\Pandora\Runs\Run|string $run, ?string $reason = null)
 * @method static string version()
 *
 * @see \Pandora\Pandora
 */
final class Pandora extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pandora\Pandora::class;
    }
}
