<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions;

/**
 * Somebody already decided about this proposal.
 *
 * Ordinary, not exceptional: two operators looking at the same queue is the
 * normal case, and the second one to press Promote deserves an explanation
 * rather than a second automation.
 */
final class ObservationNotPending extends PandoraException
{
    private function __construct(
        string $message,
        public readonly string $status,
    ) {
        parent::__construct($message);
    }

    public static function make(string $observationId, string $status): self
    {
        return new self(
            "Observation [{$observationId}] is already [{$status}] and cannot be decided again.",
            $status,
        );
    }

    public function userMessage(): string
    {
        return 'Somebody has already decided about this proposal.';
    }
}
