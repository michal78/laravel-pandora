<?php

declare(strict_types=1);

namespace Pandora\Pandora\Approvals\Enums;

/**
 * Who is being asked.
 *
 * A confirmation is the acting user saying "yes, I meant that" about their own
 * request. An approval is someone else authorising it. Conflating them would
 * let a user approve their own high-risk call, which is exactly what an
 * approval gate exists to prevent.
 */
enum ApprovalKind: string
{
    case Approval = 'approval';
    case Confirmation = 'confirmation';

    public function label(): string
    {
        return match ($this) {
            self::Approval => 'Approval',
            self::Confirmation => 'Confirmation',
        };
    }
}
