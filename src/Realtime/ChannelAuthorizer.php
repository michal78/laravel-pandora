<?php

declare(strict_types=1);

namespace Pandora\Pandora\Realtime;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Conversations\ConversationParticipant;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Core\Tenancy\TenantManager;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\UI\PandoraGate;

/**
 * Decides who may subscribe to a Pandora broadcast channel.
 *
 * Deliberately fail-closed: every method returns false unless it can
 * positively establish access. Tenant checks bypass the global scope on
 * purpose, because the point is to compare the record's tenant against the
 * subscriber's -- a scoped query would simply return nothing and we could not
 * tell "wrong tenant" from "does not exist".
 */
final class ChannelAuthorizer
{
    public function __construct(
        private readonly TenantManager $tenants,
    ) {}

    public function canAccessConversation(mixed $user, string $conversationId): bool
    {
        if (! $user instanceof Authorizable || ! PandoraGate::allows('access')) {
            return false;
        }

        /** @var Conversation|null $conversation */
        $conversation = Conversation::acrossAllTenants()->find($conversationId);

        if ($conversation === null || ! $this->sameTenant($conversation->tenant_id)) {
            return false;
        }

        $actor = ActorContext::forUser($user);

        // Creator, or an explicitly recorded participant.
        if ($conversation->created_by_id === $actor->id
            && $conversation->created_by_type === $actor->type) {
            return true;
        }

        return ConversationParticipant::acrossAllTenants()
            ->where('conversation_id', $conversationId)
            ->where('participant_type', $actor->type)
            ->where('participant_id', $actor->id)
            ->exists();
    }

    public function canAccessRun(mixed $user, string $runId): bool
    {
        if (! $user instanceof Authorizable || ! PandoraGate::allows('access')) {
            return false;
        }

        /** @var Run|null $run */
        $run = Run::acrossAllTenants()->find($runId);

        if ($run === null || ! $this->sameTenant($run->tenant_id)) {
            return false;
        }

        $actor = ActorContext::forUser($user);

        if ($run->actor_type === $actor->type && $run->actor_id === $actor->id) {
            return true;
        }

        if ($run->conversation_id !== null
            && $this->canAccessConversation($user, $run->conversation_id)) {
            return true;
        }

        // Administrators inspecting someone else's run need the explicit
        // trace-viewing ability, not merely `access`.
        return PandoraGate::allows('runs.trace.view');
    }

    public function isSameUser(mixed $user, string $userId): bool
    {
        return $user instanceof Authorizable
            && ActorContext::forUser($user)->id === $userId;
    }

    public function canAccessTenant(mixed $user, string $tenantId): bool
    {
        return $user instanceof Authorizable
            && PandoraGate::allows('access')
            && $this->sameTenant($tenantId);
    }

    public function canResolveApprovalsFor(mixed $user, string $userId): bool
    {
        return $this->isSameUser($user, $userId)
            && PandoraGate::allows('approvals.resolve');
    }

    public function canAccessSystem(mixed $user): bool
    {
        return $user instanceof Authorizable && PandoraGate::allows('settings.manage');
    }

    private function sameTenant(?string $tenantId): bool
    {
        return $tenantId === $this->tenants->currentId();
    }
}
