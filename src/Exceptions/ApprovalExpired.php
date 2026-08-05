<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions;

/**
 * A run waited for a decision nobody made.
 *
 * Deliberately its own class rather than a generic failure: "nobody
 * responded" is an operational problem with an operational fix, and an
 * operator reading a Runs page should be able to tell it apart from a tool
 * that broke.
 */
final class ApprovalExpired extends PandoraException
{
    private function __construct(
        string $message,
        public readonly string $approvalId,
        public readonly string $toolName,
    ) {
        parent::__construct($message);
    }

    public static function make(string $approvalId, string $toolName): self
    {
        return new self(
            "Approval [{$approvalId}] for tool [{$toolName}] expired before anyone responded.",
            $approvalId,
            $toolName,
        );
    }

    public function userMessage(): string
    {
        return 'This request needed approval, and it expired before anyone responded.';
    }
}
