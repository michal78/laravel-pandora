<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Realtime\ChannelAuthorizer;

/*
|--------------------------------------------------------------------------
| Pandora broadcast channels
|--------------------------------------------------------------------------
|
| Every callback re-resolves authorization SERVER-SIDE. The id embedded in a
| channel name is an input, never a claim -- a user asking to subscribe to
| `pandora.conversation.{someoneElsesId}` must be refused, and that refusal is
| the only thing standing between tenants on a shared Reverb server.
|
*/

$prefix = config('pandora.realtime.channel_prefix', 'pandora');

Broadcast::channel($prefix.'.conversation.{conversationId}',
    static fn (mixed $user, string $conversationId): bool => app(ChannelAuthorizer::class)
        ->canAccessConversation($user, $conversationId),
);

Broadcast::channel($prefix.'.run.{runId}',
    static fn (mixed $user, string $runId): bool => app(ChannelAuthorizer::class)
        ->canAccessRun($user, $runId),
);

Broadcast::channel($prefix.'.user.{userId}',
    static fn (mixed $user, string $userId): bool => app(ChannelAuthorizer::class)
        ->isSameUser($user, $userId),
);

Broadcast::channel($prefix.'.tenant.{tenantId}',
    static fn (mixed $user, string $tenantId): bool => app(ChannelAuthorizer::class)
        ->canAccessTenant($user, $tenantId),
);

Broadcast::channel($prefix.'.approvals.{userId}',
    static fn (mixed $user, string $userId): bool => app(ChannelAuthorizer::class)
        ->canResolveApprovalsFor($user, $userId),
);

Broadcast::channel($prefix.'.system',
    static fn (mixed $user): bool => app(ChannelAuthorizer::class)->canAccessSystem($user),
);

unset($prefix, $conversation, $actor);
