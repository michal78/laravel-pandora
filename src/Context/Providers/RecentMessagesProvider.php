<?php

declare(strict_types=1);

namespace Pandora\Pandora\Context\Providers;

use Pandora\Pandora\Context\ContextRequest;
use Pandora\Pandora\Context\ContextSection;
use Pandora\Pandora\Contracts\ContextProvider;
use Pandora\Pandora\Messages\Enums\MessageRole;
use Pandora\Pandora\Messages\Enums\StreamingState;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Providers\Data\ChatMessage;

/**
 * Recent conversation history, scoped to the run's SESSION.
 *
 * The session filter is the load-bearing part: querying by conversation alone
 * would pull another participant's messages into this run's context in any
 * shared conversation or shared channel inbox. Proven by
 * tests/Security/SessionIsolationTest.
 */
final class RecentMessagesProvider implements ContextProvider
{
    public function key(): string
    {
        return 'recent_messages';
    }

    public function provide(ContextRequest $request): ?ContextSection
    {
        if ($request->run->conversation_id === null) {
            return null;
        }

        /** @var int $limit */
        $limit = config('pandora.context.recent_messages.limit', 40);

        $messages = Message::query()
            ->where('conversation_id', $request->run->conversation_id)
            ->where('session_id', $request->session->getKey())
            ->whereIn('role', [MessageRole::User->value, MessageRole::Assistant->value])
            ->where('streaming_state', StreamingState::Complete->value)
            // Exclude the assistant placeholder this run is about to write into.
            ->where(function ($query) use ($request): void {
                $query->whereNull('run_id')->orWhereNot('run_id', $request->run->getKey());
            })
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        if ($messages->isEmpty()) {
            return null;
        }

        $chatMessages = $messages
            ->filter(static fn (Message $m): bool => ($m->content ?? '') !== '')
            ->map(static fn (Message $m): ChatMessage => new ChatMessage(
                role: $m->role,
                content: (string) $m->content,
            ))
            ->values()
            ->all();

        if ($chatMessages === []) {
            return null;
        }

        return ContextSection::of($this->key(), array_values($chatMessages));
    }
}
