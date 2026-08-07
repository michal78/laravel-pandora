<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures;

use Pandora\Realtime\Events\PandoraBroadcastEvent;

/**
 * A deliberately hostile broadcast event that puts secrets straight into its
 * payload, used to prove the base class redacts without the subclass asking.
 */
final class LeakyBroadcastEvent extends PandoraBroadcastEvent
{
    public function eventName(): string
    {
        return 'pandora.test.leaky';
    }

    public function broadcastOn(): array
    {
        return [];
    }

    protected function payload(): array
    {
        return [
            'api_key' => 'sk-live-LEAKED',
            'nested' => ['password' => 'hunter2'],
            'safe' => 'ok',
        ];
    }
}
