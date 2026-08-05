<?php

declare(strict_types=1);

namespace Pandora\Pandora\Realtime\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Pandora\Pandora\Support\Redactor;

/**
 * Base for every Pandora broadcast.
 *
 * Two invariants live here rather than in each subclass:
 *
 *  1. Payloads are REDACTED on the way out. There is no code path that can
 *     broadcast an unredacted payload by forgetting a call.
 *  2. Payloads are VERSIONED, so a browser holding a cached asset from a
 *     previous deploy degrades predictably instead of throwing.
 *
 * Broadcasts are notifications, never the state store: the database is
 * authoritative and the UI must reconstruct correct state from a reload alone.
 * See docs/architecture/realtime-model.md.
 */
abstract class PandoraBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public const VERSION = 1;

    /**
     * The wire name of this event.
     */
    abstract public function eventName(): string;

    /**
     * The unredacted payload. Subclasses build this freely; redaction happens
     * in broadcastWith().
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * @return list<Channel>
     */
    abstract public function broadcastOn(): array;

    final public function broadcastAs(): string
    {
        return $this->eventName();
    }

    /**
     * @return array<string, mixed>
     */
    final public function broadcastWith(): array
    {
        return [
            'event' => $this->eventName(),
            'version' => static::VERSION,
            'occurred_at' => now()->toIso8601ZuluString('millisecond'),
            'correlation_id' => $this->correlationId(),
            'data' => app(Redactor::class)->redact($this->payload()),
        ];
    }

    public function broadcastConnection(): ?string
    {
        /** @var string|null $connection */
        $connection = config('pandora.realtime.connection');

        return $connection;
    }

    /**
     * Suppress broadcasting entirely when realtime is disabled. The UI polls
     * instead and remains correct, because the database already is.
     */
    public function broadcastWhen(): bool
    {
        return (bool) config('pandora.realtime.enabled', true);
    }

    protected function correlationId(): ?string
    {
        return null;
    }

    protected static function prefix(): string
    {
        /** @var string $prefix */
        $prefix = config('pandora.realtime.channel_prefix', 'pandora');

        return $prefix;
    }

    protected static function runChannel(string $runId): PrivateChannel
    {
        return new PrivateChannel(static::prefix().'.run.'.$runId);
    }

    protected static function conversationChannel(string $conversationId): PrivateChannel
    {
        return new PrivateChannel(static::prefix().'.conversation.'.$conversationId);
    }

    protected static function tenantChannel(string $tenantId): PrivateChannel
    {
        return new PrivateChannel(static::prefix().'.tenant.'.$tenantId);
    }
}
