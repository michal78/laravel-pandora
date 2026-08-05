<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions;

use Pandora\Pandora\Approvals\Enums\ApprovalStatus;

/**
 * Someone tried to resolve an approval that was already resolved.
 *
 * The mechanism behind threat T14: two approvers pressing the button at the
 * same moment. The second one loses, loudly, having changed nothing.
 */
final class ApprovalNotPending extends PandoraException
{
    private function __construct(
        string $message,
        public readonly ApprovalStatus $status,
    ) {
        parent::__construct($message);
    }

    public static function make(string $approvalId, ApprovalStatus $status): self
    {
        return new self(
            "Approval [{$approvalId}] was already resolved as [{$status->value}].",
            $status,
        );
    }

    public function userMessage(): string
    {
        return 'This request has already been resolved by someone else.';
    }
}
