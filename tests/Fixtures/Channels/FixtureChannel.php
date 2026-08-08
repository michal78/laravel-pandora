<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures\Channels;

use Pandora\Channels\Data\DeliveryResult;
use Pandora\Channels\Data\OutboundMessage;
use Pandora\Contracts\Channel;

/**
 * A channel that stands in for one an extension package would register.
 *
 * It exists to be attributed: its namespace matches a PSR-4 prefix declared by
 * a fixture package, so the inspector can decide which package registered it.
 */
final class FixtureChannel implements Channel
{
    public function __construct(private readonly string $key = 'fixture') {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return 'Fixture channel';
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        return DeliveryResult::sent();
    }
}
