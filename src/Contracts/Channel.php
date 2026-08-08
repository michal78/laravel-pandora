<?php

declare(strict_types=1);

namespace Pandora\Contracts;

use Pandora\Channels\Data\DeliveryResult;
use Pandora\Channels\Data\OutboundMessage;

/**
 * A medium through which a conversation happens: Slack, Teams, SMS.
 *
 * Note what this interface does NOT have: any way for an adapter to say who a
 * message is from, in the sense the rest of Pandora means it. An adapter
 * reports a *participant* -- an opaque identifier in a remote system -- and the
 * core decides whether that participant is a linked host user, which is the
 * only thing tool authorization, memory scoping and budgets will accept
 * (ADR-0015).
 *
 * That absence is deliberate and is the main thing this contract is for. An
 * extension author cannot get identity wrong by omission when there is no field
 * for the mistake.
 *
 * An adapter receives inbound traffic however its remote system delivers it --
 * usually its own signed webhook route, registered by its own service provider
 * -- and hands the result to `ChannelInbox::receive()` as an `InboundMessage`.
 * Pandora does not own that route, because every channel authenticates its
 * callbacks differently and a generic endpoint would have to trust the payload
 * to tell it which check to run.
 */
interface Channel
{
    /**
     * The adapter key. Stable, lowercase, and the value stored in
     * `pandora_channel_accounts.channel`.
     */
    public function key(): string;

    /** The human name shown in the control center. */
    public function name(): string;

    /**
     * Deliver one message.
     *
     * Never throws for an ordinary delivery failure: a channel that is down is
     * a recorded failure on a run, not an exception in a worker. Return
     * `DeliveryResult::failed()` and the reason is stored and shown.
     */
    public function send(OutboundMessage $message): DeliveryResult;
}
