<?php

declare(strict_types=1);

namespace Pandora\Pandora\Conversations;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Core\Tenancy\TenantManager;

final class ConversationManager
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TenantManager $tenants,
    ) {}

    public function start(
        Agent $agent,
        ?ActorContext $actor = null,
        ?string $title = null,
        string $channel = 'web',
    ): Conversation {
        return $this->connection->transaction(function () use ($agent, $actor, $title, $channel): Conversation {
            /** @var Conversation $conversation */
            $conversation = Conversation::query()->create([
                'tenant_id' => $this->tenants->currentId(),
                'agent_id' => $agent->getKey(),
                'title' => $title,
                'channel' => $channel,
                'status' => 'active',
                'created_by_type' => $actor?->type,
                'created_by_id' => $actor?->id,
                'last_activity_at' => now(),
            ]);

            if ($actor !== null && ! $actor->isSystem() && $actor->id !== null) {
                ConversationParticipant::query()->create([
                    'tenant_id' => $conversation->tenant_id,
                    'conversation_id' => $conversation->getKey(),
                    'participant_type' => $actor->type,
                    'participant_id' => $actor->id,
                    'role' => 'owner',
                    'joined_at' => now(),
                ]);
            }

            return $conversation;
        });
    }

    /**
     * Derive a title from the first user message.
     *
     * Deliberately mechanical rather than model-generated: a title is not
     * worth a model call, a queue job and a cost record, and this is
     * predictable. Model-generated titles are an opt-in for a later phase.
     */
    public function titleFromMessage(Conversation $conversation, string $message): Conversation
    {
        if ($conversation->title !== null) {
            return $conversation;
        }

        $title = Str::of($message)
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->limit(60)
            ->toString();

        $conversation->forceFill([
            'title' => $title === '' ? 'New conversation' : $title,
        ])->save();

        return $conversation;
    }

    public function archive(Conversation $conversation): Conversation
    {
        $conversation->forceFill(['status' => 'archived'])->save();

        return $conversation;
    }

    public function restore(Conversation $conversation): Conversation
    {
        $conversation->forceFill(['status' => 'active'])->save();

        return $conversation;
    }
}
