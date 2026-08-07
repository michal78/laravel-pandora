<?php

declare(strict_types=1);

namespace Pandora\Automation\Webhooks;

use Illuminate\Support\Carbon;
use Pandora\Exceptions\WebhookRejected;

/**
 * The signature on an inbound webhook.
 *
 * Format, deliberately the same shape several well-known providers use, so an
 * integrator has seen it before:
 *
 *     X-Pandora-Signature: t=1754400000,v1=<hex sha256 hmac>
 *
 * The signed string is `"{timestamp}.{raw body}"`, not the body alone. Signing
 * only the body means a captured request stays valid forever, and the
 * timestamp being inside the MAC is what stops an attacker rewriting it.
 *
 * Timestamp tolerance is a narrowing, not a defence. Inside the window the
 * same request can be replayed as often as anybody likes -- which is what the
 * delivery nonce is for.
 */
final readonly class WebhookSignature
{
    public function __construct(
        public int $timestamp,
        public string $hash,
    ) {}

    public static function parse(?string $header): self
    {
        if ($header === null || trim($header) === '') {
            throw WebhookRejected::missingSignature();
        }

        $parts = [];

        foreach (explode(',', $header) as $segment) {
            $pair = explode('=', trim($segment), 2);

            if (count($pair) === 2) {
                $parts[trim($pair[0])] = trim($pair[1]);
            }
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            throw WebhookRejected::malformedSignature();
        }

        return new self((int) $parts['t'], $parts['v1']);
    }

    /**
     * What a sender puts in the header. Used by the tests and by the control
     * center's "how to call this" panel.
     */
    public static function sign(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= Carbon::now()->getTimestamp();

        return sprintf('t=%d,v1=%s', $timestamp, self::digest($secret, $body, $timestamp));
    }

    /**
     * @throws WebhookRejected
     */
    public function verify(string $secret, string $body, int $toleranceSeconds): void
    {
        if (abs(Carbon::now()->getTimestamp() - $this->timestamp) > $toleranceSeconds) {
            throw WebhookRejected::staleTimestamp($toleranceSeconds);
        }

        // Constant time. A `!==` here leaks the correct digest one byte at a
        // time to anybody willing to make enough requests, and this endpoint
        // is by definition reachable by anybody.
        if (! hash_equals(self::digest($secret, $body, $this->timestamp), $this->hash)) {
            throw WebhookRejected::badSignature();
        }
    }

    private static function digest(string $secret, string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
