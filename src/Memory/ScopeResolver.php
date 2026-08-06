<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory;

use Pandora\Pandora\Conversations\Session;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Core\Tenancy\TenantManager;
use Pandora\Pandora\Memory\Enums\MemoryScope;

/**
 * Derives what a runner may see, from the session it is running in.
 *
 * The session is the input rather than the conversation, for the same reason
 * `ContextRequest` carries one: a conversation can have several participants,
 * and a retrieval keyed on the conversation would hand one participant's
 * private memory to another. The session binds
 * (tenant, agent, actor, channel, origin) and is the security boundary.
 *
 * A system actor -- a scheduled automation, a webhook, a delegating run --
 * resolves to NO user scope at all. That is deliberate and it is the property
 * most likely to be "fixed" by someone debugging why a nightly automation
 * cannot see a user's preferences. It cannot see them because nobody is
 * standing there to have consented to it seeing them.
 */
final readonly class ScopeResolver
{
    public function __construct(
        private TenantManager $tenants,
    ) {}

    /**
     * @param string|null $workspaceId the agent's workspace, when it has one.
     *                                 Passed rather than read off the agent so
     *                                 a caller that has already loaded the
     *                                 agent does not pay for a second query,
     *                                 and so this class needs no opinion about
     *                                 where an agent's workspace comes from.
     */
    public function forSession(Session $session, ?string $workspaceId = null): MemoryScopeSet
    {
        $pairs = [];

        $pairs[] = ['scope' => MemoryScope::Tenant, 'scope_id' => null];

        $userScopeId = self::userScopeId($session->actor_type, $session->actor_id);

        if ($userScopeId !== null) {
            $pairs[] = ['scope' => MemoryScope::User, 'scope_id' => $userScopeId];
        }

        $pairs[] = ['scope' => MemoryScope::Agent, 'scope_id' => $session->agent_id];

        if ($session->conversation_id !== null) {
            $pairs[] = ['scope' => MemoryScope::Conversation, 'scope_id' => $session->conversation_id];
        }

        if ($workspaceId !== null && $workspaceId !== '') {
            $pairs[] = ['scope' => MemoryScope::Workspace, 'scope_id' => $workspaceId];
        }

        return MemoryScopeSet::of($pairs, $this->tenants->currentId());
    }

    /**
     * The identifier a `user`-scoped memory is filed under.
     *
     * Composite rather than the bare key, because the host owns the user table
     * and two authenticatable models can both have an id of 1. A bare key
     * would alias an admin onto a customer.
     *
     * A system actor has no user identity and gets null -- not a placeholder,
     * which would be a shared bucket every automation could read.
     */
    public static function userScopeId(?string $actorType, ?string $actorId): ?string
    {
        if ($actorType === null || $actorId === null || $actorType === 'system') {
            return null;
        }

        return $actorType.'#'.$actorId;
    }

    public static function userScopeIdFor(ActorContext $actor): ?string
    {
        if ($actor->isSystem()) {
            return null;
        }

        return self::userScopeId($actor->type, $actor->id);
    }
}
