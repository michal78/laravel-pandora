<?php

declare(strict_types=1);

namespace Pandora\Channels\Data;

/**
 * One message arriving from a channel.
 *
 * Every field here is untrusted input at the grade of a fetched web page: text
 * a stranger typed, a display name a stranger chose, identifiers a remote
 * system minted. `text` reaches a model labelled as foreign content and never
 * as instruction; `participantDisplayName` is escaped wherever it is rendered
 * and is privileged nowhere.
 *
 * There is no actor, user, email or tenant field, and that is the point. An
 * adapter reports what the remote system said; who that is -- if anyone -- is
 * resolved by `ChannelInbox` from the link table alone (ADR-0015).
 */
final readonly class InboundMessage
{
    /**
     * @param string $channelKey the registered adapter key, e.g. 'slack'
     * @param string $accountExternalId what the remote system calls the workspace this arrived in
     * @param string $participantExternalId the remote system's ID for the sender
     * @param string|null $externalMessageId the remote system's ID for this message; the idempotency key
     * @param string|null $conversationExternalId the remote channel or thread, carried so a reply lands where the message did
     * @param array<int, array<string, mixed>> $attachments
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $channelKey,
        public string $accountExternalId,
        public string $participantExternalId,
        public string $text,
        public ?string $participantDisplayName = null,
        public ?string $externalMessageId = null,
        public ?string $conversationExternalId = null,
        public array $attachments = [],
        public array $raw = [],
    ) {}

    /**
     * The normalised text, used for matching the linking command.
     *
     * Matching is done on a trimmed, case-folded copy and never on the stored
     * value, so the transcript keeps exactly what the person typed.
     */
    public function normalisedText(): string
    {
        return mb_strtolower(trim($this->text));
    }
}
