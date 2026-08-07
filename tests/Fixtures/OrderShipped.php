<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures;

/**
 * A plain host application event.
 *
 * Deliberately not an Eloquent model and not serializable-by-magic: an event
 * binding's job is to decide what a model gets told, and a fixture that
 * happened to serialise cleanly would hide the fact that Pandora never sends
 * the event object itself.
 */
final class OrderShipped
{
    public function __construct(
        public readonly string $reference = 'ORD-1',
        public readonly bool $international = false,
    ) {}
}
