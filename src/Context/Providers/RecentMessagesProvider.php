<?php

declare(strict_types=1);

namespace Pandora\Context\Providers;

use Pandora\Context\ContextRequest;
use Pandora\Context\ContextSection;
use Pandora\Contracts\ContextProvider;
use Pandora\Messages\Enums\MessageRole;
use Pandora\Messages\Enums\StreamingState;
use Pandora\Messages\Message;
use Pandora\Providers\Data\ChatMessage;

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
            ->whereIn('role', [
                MessageRole::User->value,
                MessageRole::Assistant->value,
                MessageRole::Tool->value,
            ])
            // Excludes the placeholder this run is about to stream into, while
            // keeping the earlier iterations of this same run: a tool loop is
            // only coherent if the model can see what it already asked for.
            ->where('streaming_state', StreamingState::Complete->value)
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        if ($messages->isEmpty()) {
            return null;
        }

        $chatMessages = array_values($messages
            ->filter(static fn (Message $m): bool => ($m->content ?? '') !== '' || $m->requestsTools())
            ->map(static fn (Message $m): ChatMessage => new ChatMessage(
                role: $m->role,
                content: (string) $m->content,
                toolCallId: $m->tool_call_id,
                toolCalls: $m->toolCalls(),
            ))
            ->all());

        if ($chatMessages === []) {
            return null;
        }

        // Deliberately NOT repaired here. A recency window can cut a tool loop
        // in half, and a stored conversation can interleave a message between
        // a request and its answer -- both invalid, both fixed once for every
        // provider in TranscriptNormaliser, on the way out.
        return ContextSection::of($this->key(), $chatMessages);
    }
}
