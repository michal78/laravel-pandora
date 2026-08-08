<?php

declare(strict_types=1);

namespace Pandora\Channels\Data;

/**
 * What an adapter reports back about one delivery attempt.
 *
 * A failure is a value, not an exception, because an unreachable channel is an
 * ordinary operational fact: the reply is recorded, visible in the control
 * center and unsent. It is never re-routed to another channel -- a private
 * answer arriving somewhere nobody chose is a disclosure, and "at least it got
 * through" is not a security property.
 */
final readonly class DeliveryResult
{
    private function __construct(
        public bool $delivered,
        public ?string $externalMessageId,
        public ?string $error,
    ) {}

    public static function sent(?string $externalMessageId = null): self
    {
        return new self(true, $externalMessageId, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
