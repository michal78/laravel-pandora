<?php

declare(strict_types=1);

namespace Pandora\Pandora\Realtime\Events;

/**
 * A coalesced chunk of streamed assistant output.
 *
 * `sequence` is monotonic per message. A client detecting a gap does not try
 * to repair it -- it refetches the message from the database, which is simple
 * and correct by construction.
 */
final class AssistantDeltaReceived extends PandoraBroadcastEvent
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
        public readonly string $runId,
        public readonly string $delta,
        public readonly int $sequence,
        public readonly ?string $correlationId = null,
    ) {}

    public function eventName(): string
    {
        return 'pandora.assistant.delta';
    }

    public function broadcastOn(): array
    {
        return [self::conversationChannel($this->conversationId)];
    }

    protected function correlationId(): ?string
    {
        return $this->correlationId;
    }

    protected function payload(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'run_id' => $this->runId,
            'delta' => $this->delta,
            'sequence' => $this->sequence,
        ];
    }
}
