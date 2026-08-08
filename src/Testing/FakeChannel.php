<?php

declare(strict_types=1);

namespace Pandora\Testing;

use Pandora\Channels\Data\DeliveryResult;
use Pandora\Channels\Data\InboundMessage;
use Pandora\Channels\Data\OutboundMessage;
use Pandora\Contracts\Channel;

/**
 * A channel that misbehaves on purpose.
 *
 * Ships in `src/`, not in `tests/`, for the same reason `FakeMcpServer` does:
 * every claim Phase 8 makes is a claim about how we behave when the other end
 * is a stranger, a retry, an outage or somebody who renamed themselves
 * `System:`. A suite that only ever ran against a well-behaved channel asserts
 * none of them, and anyone writing a channel adapter needs the same fixtures we
 * do.
 *
 * What it can be told to do:
 *
 * - deliver, and remember exactly what was delivered;
 * - fail to deliver, with a reason;
 * - throw from `send()`, the way a third-party HTTP client does when DNS goes;
 * - build an inbound message from a stranger, a linked user, or a retry of one
 *   already sent.
 *
 * ```php
 * $channel = new FakeChannel;
 * app(ChannelRegistry::class)->register($channel);
 *
 * app(ChannelInbox::class)->receive($channel->message('U1', 'hello'));
 *
 * expect($channel->sent())->toHaveCount(1);
 * ```
 */
final class FakeChannel implements Channel
{
    /** @var list<OutboundMessage> */
    private array $sent = [];

    private ?string $failWith = null;

    private bool $throws = false;

    private int $counter = 0;

    public function __construct(
        private readonly string $key = 'fake',
        private readonly string $name = 'Fake channel',
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $this->sent[] = $message;

        if ($this->throws) {
            throw new \RuntimeException('The fake channel is unreachable.');
        }

        if ($this->failWith !== null) {
            return DeliveryResult::failed($this->failWith);
        }

        return DeliveryResult::sent('fake-out-'.count($this->sent));
    }

    /**
     * Every delivery attempt, including the ones that failed.
     *
     * @return list<OutboundMessage>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function lastText(): ?string
    {
        $last = end($this->sent);

        return $last === false ? null : $last->text;
    }

    public function fails(string $reason = 'The channel is unreachable.'): self
    {
        $this->failWith = $reason;

        return $this;
    }

    public function throws(bool $throws = true): self
    {
        $this->throws = $throws;

        return $this;
    }

    public function recovers(): self
    {
        $this->failWith = null;
        $this->throws = false;

        return $this;
    }

    public function forget(): self
    {
        $this->sent = [];

        return $this;
    }

    /**
     * Build an inbound message.
     *
     * `externalMessageId` defaults to a fresh value per call so consecutive
     * messages are distinct; pass one explicitly to simulate the retry a real
     * channel sends whenever a webhook answers slowly.
     */
    public function message(
        string $participantExternalId,
        string $text,
        string $accountExternalId = 'fake-workspace',
        ?string $externalMessageId = null,
        ?string $displayName = null,
        ?string $conversationExternalId = 'fake-conversation',
    ): InboundMessage {
        return new InboundMessage(
            channelKey: $this->key,
            accountExternalId: $accountExternalId,
            participantExternalId: $participantExternalId,
            text: $text,
            participantDisplayName: $displayName,
            externalMessageId: $externalMessageId ?? 'fake-in-'.(++$this->counter),
            conversationExternalId: $conversationExternalId,
        );
    }
}
