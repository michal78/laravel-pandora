<?php

declare(strict_types=1);

namespace Pandora\Automation\Enums;

/**
 * The life of a piece of work an agent proposed for itself.
 *
 * It never leaves `pending` without a human, which is the whole difference
 * between a leashed agent and a daemon.
 */
enum ObservationStatus: string
{
    case Pending = 'pending';
    case Promoted = 'promoted';
    case Dismissed = 'dismissed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting a decision',
            self::Promoted => 'Promoted to an automation',
            self::Dismissed => 'Dismissed',
            self::Expired => 'Expired',
        };
    }
}
