<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

/**
 * Routing found nothing it was allowed to use.
 *
 * Carries the reason each candidate was passed over. "No model available" on
 * its own sends an operator hunting through four config files; "gpt-4o:
 * provider degraded, claude-sonnet-4-5: not permitted for this tenant" does
 * not.
 */
final class NoModelAvailable extends PandoraException
{
    /**
     * @param list<string> $skipped
     */
    public function __construct(
        string $message,
        public readonly array $skipped = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $skipped
     */
    public static function forAgent(string $agentSlug, array $skipped): self
    {
        $detail = $skipped === []
            ? 'no candidates were configured at all'
            : implode('; ', $skipped);

        return new self(
            "No model is available for agent [{$agentSlug}]: {$detail}.",
            $skipped,
        );
    }

    public function userMessage(): string
    {
        return 'No AI model is currently available to answer this.';
    }
}
