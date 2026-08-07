<?php

declare(strict_types=1);

namespace Pandora\Approvals\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Approved => 'Approved',
            self::Denied => 'Denied',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Semantic colour token; mapped to classes by the UI, not here. */
    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Denied => 'danger',
            self::Pending => 'warning',
            default => 'muted',
        };
    }
}
