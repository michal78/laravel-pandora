<?php

declare(strict_types=1);

namespace Pandora\Channels;

use Pandora\Audit\AuditLogger;
use Pandora\Channels\Data\DeliveryResult;
use Pandora\Channels\Data\OutboundMessage;
use Pandora\Channels\Enums\DeliveryDirection;
use Pandora\Channels\Enums\DeliveryStatus;
use Pandora\Support\Redactor;
use Throwable;

/**
 * Sends one message out through an adapter, and records what happened.
 *
 * The rule this class exists to keep is negative: a message that cannot be
 * delivered is never delivered somewhere else. No fallback channel, no email
 * copy, no retry into a different account. A private answer arriving somewhere
 * nobody chose is a disclosure, and it is the kind that looks like helpfulness
 * in the code review that introduces it.
 *
 * So a failure becomes a row: visible on the run, visible on the Channels page,
 * and unsent. That is a state an operator can act on, which is what makes the
 * refusal to improvise acceptable.
 */
final class ChannelDispatcher
{
    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly AuditLogger $audit,
        private readonly Redactor $redactor,
    ) {}

    public function send(OutboundMessage $message): DeliveryResult
    {
        $account = $message->account;

        if (! $account->enabled) {
            return $this->record($message, DeliveryResult::failed('The channel account is disabled.'));
        }

        $adapter = $this->channels->get($account->channel);

        if ($adapter === null) {
            return $this->record($message, DeliveryResult::failed(
                "No adapter is registered for channel [{$account->channel}]. The extension that "
                    .'provided it may have been removed.',
            ));
        }

        try {
            $result = $adapter->send($message);
        } catch (Throwable $e) {
            // An adapter is third-party code and may throw whatever it likes.
            // A channel being down must not fail a queue worker, so the
            // exception becomes the same recorded failure any other
            // undeliverable message gets.
            $result = DeliveryResult::failed($this->summarise($e));
        }

        return $this->record($message, $result);
    }

    private function record(OutboundMessage $message, DeliveryResult $result): DeliveryResult
    {
        ChannelDelivery::query()->create([
            'tenant_id' => $message->account->tenant_id,
            'account_id' => $message->account->getKey(),
            'identity_id' => $message->identity->getKey(),
            'run_id' => $message->runId,
            'direction' => DeliveryDirection::Outbound,
            'external_message_id' => $result->externalMessageId,
            'status' => $result->delivered ? DeliveryStatus::Sent : DeliveryStatus::Failed,
            'error' => $result->error,
            'metadata' => $this->redactor->redact($message->metadata),
        ]);

        if (! $result->delivered) {
            $this->audit->record(
                action: 'channel.delivery_failed',
                targetType: ChannelAccount::class,
                targetId: (string) $message->account->getKey(),
                runId: $message->runId,
                severity: 'warning',
                metadata: [
                    'channel' => $message->account->channel,
                    'identity_id' => $message->identity->getKey(),
                    'error' => $result->error,
                ],
            );
        }

        return $result;
    }

    /**
     * A message an operator can read, without the stack trace an exception
     * from somebody else's HTTP client would otherwise drag into the database.
     */
    private function summarise(Throwable $e): string
    {
        return $e::class.': '.mb_substr($e->getMessage(), 0, 500);
    }
}
