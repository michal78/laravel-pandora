<?php

declare(strict_types=1);

namespace Pandora\Runs\Enums;

/**
 * What caused a run. Every run has one -- there is no such thing as an
 * untriggered run, which is what makes autonomous behaviour attributable.
 */
enum TriggerType: string
{
    case UserMessage = 'user_message';
    case Application = 'application';
    case Console = 'console';
    case Schedule = 'schedule';
    case Event = 'event';
    case Webhook = 'webhook';
    case Api = 'api';
    case Channel = 'channel';
    case Delegation = 'delegation';
    case Manual = 'manual';
    case Heartbeat = 'heartbeat';

    /** Triggers not initiated by a human in the moment; subject to autonomy limits. */
    public function isAutonomous(): bool
    {
        return in_array($this, [
            self::Schedule,
            self::Event,
            self::Heartbeat,
        ], true);
    }
}
