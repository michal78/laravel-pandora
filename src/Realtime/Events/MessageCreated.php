<?php

declare(strict_types=1);

namespace Pandora\Realtime\Events;

use Pandora\Messages\Message;

/**
 * A new message exists. Again content-free -- the client refetches.
 */
final class MessageCreated extends PandoraBroadcastEvent
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
        public readonly string $role,
        public readonly ?string $correlationId = null,
    ) {}

    public static function from(Message $message, ?string $correlationId = null): self
    {
        return new self(
            conversationId: $message->conversation_id,
            messageId: (string) $message->getKey(),
            role: $message->role->value,
            correlationId: $correlationId,
        );
    }

    public function eventName(): string
    {
        return 'pandora.message.created';
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
            'role' => $this->role,
        ];
    }
}
