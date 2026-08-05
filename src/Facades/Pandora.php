<?php

declare(strict_types=1);

namespace Pandora\Pandora\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Pandora\Pandora\Agents\PendingAgentRun agent(string|\Pandora\Pandora\Agents\Agent $agent)
 * @method static \Pandora\Pandora\Agents\AgentRegistry define(string|list<class-string<\Pandora\Pandora\Contracts\AgentDefinition>> $definitions)
 * @method static \Pandora\Pandora\Agents\AgentRegistry agents()
 * @method static \Pandora\Pandora\Automation\PendingEventTrigger on(string $eventClass)
 * @method static \Pandora\Pandora\Automation\AutomationScheduler automations()
 * @method static \Pandora\Pandora\Providers\ProviderManager providers()
 * @method static \Pandora\Pandora\Conversations\ConversationManager conversations()
 * @method static \Pandora\Pandora\Conversations\Conversation startConversation(string|\Pandora\Pandora\Agents\Agent $agent, ?\Pandora\Pandora\Core\Actor\ActorContext $actor = null, ?string $title = null, string $channel = 'web')
 * @method static \Pandora\Pandora\Runs\Run cancel(\Pandora\Pandora\Runs\Run|string $run, ?string $reason = null)
 * @method static string version()
 *
 * @see \Pandora\Pandora\Pandora
 */
final class Pandora extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Pandora\Pandora\Pandora::class;
    }
}
