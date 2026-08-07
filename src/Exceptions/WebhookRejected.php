<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

/**
 * An inbound webhook will not be honoured.
 *
 * `userMessage()` is deliberately uniform across every reason. A caller
 * learning which of "wrong secret", "stale timestamp" and "no such automation"
 * applied is being handed an oracle, and the status codes already distinguish
 * what a legitimate integrator needs.
 */
final class WebhookRejected extends PandoraException
{
    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function missingSignature(): self
    {
        return new self('No signature header was supplied.', 'missing_signature', 401);
    }

    public static function malformedSignature(): self
    {
        return new self('The signature header could not be parsed.', 'malformed_signature', 401);
    }

    public static function badSignature(): self
    {
        return new self('The signature did not match the payload.', 'bad_signature', 401);
    }

    public static function staleTimestamp(int $toleranceSeconds): self
    {
        return new self(
            "The signature timestamp is outside the {$toleranceSeconds}s tolerance.",
            'stale_timestamp',
            401,
        );
    }

    /** A delivery whose signature has been seen before. */
    public static function replay(): self
    {
        return new self('This delivery has already been processed.', 'replay', 409);
    }

    public static function notConfigured(string $slug): self
    {
        return new self("Automation [{$slug}] has no webhook secret configured.", 'not_configured', 404);
    }

    public static function payloadTooLarge(int $limit): self
    {
        return new self("The payload exceeds the {$limit}-byte limit.", 'payload_too_large', 413);
    }

    public function userMessage(): string
    {
        return 'The request was not accepted.';
    }
}
