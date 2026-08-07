<?php

declare(strict_types=1);

namespace Pandora\Realtime\Events;

/**
 * The assistant message is final. Carries no content: the client refetches
 * from the database, which is the authoritative copy.
 */
final class AssistantMessageCompleted extends PandoraBroadcastEvent
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
        public readonly string $runId,
        public readonly bool $failed = false,
        public readonly ?string $correlationId = null,
    ) {}

    public function eventName(): string
    {
        return 'pandora.assistant.completed';
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
            'failed' => $this->failed,
        ];
    }
}
