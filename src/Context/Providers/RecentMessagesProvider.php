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
use Pandora\Pandora\Providers\Data\ToolCall;

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

        $chatMessages = $this->dropOrphanedToolMessages($chatMessages);

        if ($chatMessages === []) {
            return null;
        }

        return ContextSection::of($this->key(), $chatMessages);
    }

    /**
     * Keep tool requests and tool results together.
     *
     * A recency window can cut a tool loop in half, and every provider rejects
     * the halves: a result whose request is missing, or a request whose
     * results are. Dropping the orphans costs a little history and keeps the
     * request valid, which is the right trade -- the alternative is a run that
     * fails for a reason no operator can see.
     *
     * @param list<ChatMessage> $messages
     * @return list<ChatMessage>
     */
    private function dropOrphanedToolMessages(array $messages): array
    {
        $requested = [];

        foreach ($messages as $message) {
            foreach ($message->toolCalls as $call) {
                $requested[$call->id] = true;
            }
        }

        $withRequests = array_values(array_filter(
            $messages,
            static fn (ChatMessage $m): bool => $m->role !== MessageRole::Tool
                || ($m->toolCallId !== null && isset($requested[$m->toolCallId])),
        ));

        $answered = [];

        foreach ($withRequests as $message) {
            if ($message->role === MessageRole::Tool && $message->toolCallId !== null) {
                $answered[$message->toolCallId] = true;
            }
        }

        $kept = [];

        foreach ($withRequests as $message) {
            if (! $message->requestsTools()) {
                $kept[] = $message;

                continue;
            }

            $unanswered = array_filter(
                $message->toolCalls,
                static fn (ToolCall $call): bool => ! isset($answered[$call->id]),
            );

            if ($unanswered === []) {
                $kept[] = $message;

                continue;
            }

            if ($message->content !== '') {
                $kept[] = ChatMessage::assistant($message->content);
            }
        }

        return $kept;
    }
}
