<?php

declare(strict_types=1);

namespace Pandora\Channels\Data;

use Pandora\Channels\ChannelIdentity;
use Pandora\Runs\Run;

/**
 * What became of one inbound message.
 *
 * Returned to the adapter so it can answer its webhook honestly. Every outcome
 * except `Accepted` is a normal one: an unknown workspace, a disabled account,
 * a retry and a stranger are all things a real channel does daily, and none of
 * them is an error worth throwing.
 */
final readonly class InboundResult
{
    private function __construct(
        public InboundOutcome $outcome,
        public ?ChannelIdentity $identity = null,
        public ?Run $run = null,
        public ?string $detail = null,
    ) {}

    public static function accepted(ChannelIdentity $identity, Run $run): self
    {
        return new self(InboundOutcome::Accepted, $identity, $run);
    }

    /** A retry of something already handled. The first outcome stands. */
    public static function duplicate(?ChannelIdentity $identity = null): self
    {
        return new self(InboundOutcome::Duplicate, $identity);
    }

    /** The sender is not linked to any host user, so nothing was created. */
    public static function unlinked(ChannelIdentity $identity): self
    {
        return new self(InboundOutcome::Unlinked, $identity);
    }

    /** The sender asked to link and has been sent a code. */
    public static function linkCodeIssued(ChannelIdentity $identity): self
    {
        return new self(InboundOutcome::LinkCodeIssued, $identity);
    }

    /** No enabled account for this workspace, or no agent behind it. */
    public static function refused(string $detail): self
    {
        return new self(InboundOutcome::Refused, null, null, $detail);
    }
}
