<?php

declare(strict_types=1);

namespace Pandora\Pandora\Context;

use Pandora\Pandora\Conversations\Session;
use Pandora\Pandora\Memory\Enums\MemoryScope;
use Pandora\Pandora\Memory\Enums\MemorySource;
use Pandora\Pandora\Memory\Enums\MemoryType;
use Pandora\Pandora\Memory\MemoryItem;
use Pandora\Pandora\Messages\Enums\MessageRole;
use Pandora\Pandora\Messages\Enums\StreamingState;
use Pandora\Pandora\Messages\Message;

/**
 * Compresses a conversation into a stored summary.
 *
 * The summary is an ARTEFACT, regenerated when the conversation has grown past
 * a threshold since the last one -- not something recomputed per request. Two
 * reasons, and the second is the one that bites: a per-request summary costs a
 * model call on every turn, and it makes the same conversation produce
 * different context twice, so an agent that answered correctly at 10:00 and
 * wrongly at 10:01 gives you nothing to compare.
 *
 * A summary is stored as a `MemoryItem` of type `Summary`, scoped to the
 * conversation. That is not a convenience: it means a summary expires,
 * redacts, exports and is forgotten by exactly the same machinery as every
 * other memory, rather than being a second store with its own half of each
 * feature.
 *
 * Summaries are scoped to the SESSION's conversation and written with the
 * session's own message set. A summary built from the whole conversation would
 * be a laundering route around session isolation: unreadable messages in,
 * readable summary out.
 */
final class Summariser
{
    public function __construct(
        private readonly int $threshold = 20,
    ) {}

    public static function fromConfig(): self
    {
        /** @var int $threshold */
        $threshold = config('pandora.context.summarisation.threshold', 20);

        return new self($threshold);
    }

    /**
     * Whether enough has been said since the last summary to warrant another.
     */
    public function isDue(Session $session): bool
    {
        if ($session->conversation_id === null) {
            return false;
        }

        return $this->messagesSinceLastSummary($session) >= $this->threshold;
    }

    /**
     * The current summary for this session's conversation, if there is one.
     */
    public function current(Session $session): ?MemoryItem
    {
        if ($session->conversation_id === null) {
            return null;
        }

        /** @var MemoryItem|null $item */
        $item = MemoryItem::query()
            ->retrievable()
            ->where('scope', MemoryScope::Conversation->value)
            ->where('scope_id', $session->conversation_id)
            ->where('type', MemoryType::Summary->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $item;
    }

    /**
     * Store a summary, replacing the one it supersedes.
     *
     * The previous summary is soft-deleted rather than kept, because two live
     * summaries of one conversation both match a retrieval and the model is
     * handed two overlapping accounts of the same events.
     */
    public function store(Session $session, string $summary): MemoryItem
    {
        $previous = $this->current($session);

        $item = new MemoryItem([
            'scope' => MemoryScope::Conversation->value,
            'scope_id' => $session->conversation_id,
            'agent_id' => $session->agent_id,
            'type' => MemoryType::Summary->value,
            'title' => 'Conversation so far',
            'content' => $summary,
            'source' => MemorySource::Summariser->value,
            'provenance' => [
                'session_id' => $session->getKey(),
                'supersedes' => $previous?->getKey(),
                'messages_covered' => $this->messageCount($session),
            ],
            'metadata' => ['summarised_message_count' => $this->messageCount($session)],
        ]);

        $item->save();

        $previous?->delete();

        return $item;
    }

    /**
     * The transcript a summary should be built from -- this session's messages
     * only.
     *
     * @return list<Message>
     */
    public function transcript(Session $session): array
    {
        if ($session->conversation_id === null) {
            return [];
        }

        /** @var list<Message> $messages */
        $messages = Message::query()
            ->where('conversation_id', $session->conversation_id)
            ->where('session_id', $session->getKey())
            ->whereIn('role', [MessageRole::User->value, MessageRole::Assistant->value])
            ->where('streaming_state', StreamingState::Complete->value)
            ->orderBy('sequence')
            ->get()
            ->all();

        return $messages;
    }

    private function messageCount(Session $session): int
    {
        return count($this->transcript($session));
    }

    private function messagesSinceLastSummary(Session $session): int
    {
        $covered = 0;
        $current = $this->current($session);

        if ($current !== null) {
            $metadata = $current->metadata ?? [];
            $covered = is_int($metadata['summarised_message_count'] ?? null)
                ? $metadata['summarised_message_count']
                : 0;
        }

        return max(0, $this->messageCount($session) - $covered);
    }
}
