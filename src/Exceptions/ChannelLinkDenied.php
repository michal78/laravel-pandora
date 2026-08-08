<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

/**
 * A linking attempt was refused.
 *
 * Every message here is deliberately the same shape and gives away nothing
 * about which part failed to somebody guessing: an unknown code, an expired
 * code and a code belonging to another identity all read alike from outside.
 * The `reason` is what gets audited, so an operator reading the log sees the
 * distinction the person at the keyboard does not.
 */
final class ChannelLinkDenied extends PandoraException
{
    public readonly string $reason;

    private function __construct(string $message, string $reason)
    {
        parent::__construct($message);

        $this->reason = $reason;
    }

    public static function invalidCode(string $reason = 'invalid_code'): self
    {
        return new self(
            'That linking code is not valid. Codes expire quickly and work once — ask for a new '
                .'one in the channel and try again.',
            $reason,
        );
    }

    public static function rateLimited(): self
    {
        return new self(
            'Too many linking attempts. Wait a few minutes and try again.',
            'rate_limited',
        );
    }

    /**
     * The identity is already linked to somebody else.
     *
     * Refused rather than silently re-pointed: taking an identity from one
     * account and giving it to another is exactly the transition that must
     * never be a side effect of redeeming a code.
     */
    public static function alreadyLinked(): self
    {
        return new self(
            'That channel account is already linked to a user. Unlink it first.',
            'already_linked',
        );
    }
}
