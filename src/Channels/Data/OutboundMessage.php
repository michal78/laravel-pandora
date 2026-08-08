<?php

declare(strict_types=1);

namespace Pandora\Channels\Data;

use Pandora\Channels\ChannelAccount;
use Pandora\Channels\ChannelIdentity;

/**
 * One message Pandora is asking a channel to deliver.
 *
 * The adapter is given the account (so it can resolve its own credential by
 * key) and the identity (so it knows where to send), and nothing about the host
 * user behind that identity. An adapter has no business knowing which employee
 * a Slack handle belongs to, and cannot leak what it was never given.
 */
final readonly class OutboundMessage
{
    /**
     * @param string|null $conversationExternalId the remote channel or thread to reply in
     * @param string|null $replyToExternalId the message being answered, where the channel supports threading
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ChannelAccount $account,
        public ChannelIdentity $identity,
        public string $text,
        public ?string $conversationExternalId = null,
        public ?string $replyToExternalId = null,
        public ?string $runId = null,
        public array $metadata = [],
    ) {}
}
