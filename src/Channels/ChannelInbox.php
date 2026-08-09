<?php

declare(strict_types=1);

namespace Pandora\Channels;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLogger;
use Pandora\Channels\Data\InboundMessage;
use Pandora\Channels\Data\InboundResult;
use Pandora\Channels\Data\OutboundMessage;
use Pandora\Channels\Enums\DeliveryDirection;
use Pandora\Channels\Enums\DeliveryStatus;
use Pandora\Conversations\Conversation;
use Pandora\Conversations\ConversationManager;
use Pandora\Core\Actor\ActorManager;
use Pandora\Core\Tenancy\TenantContext;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Exceptions\ChannelLinkDenied;
use Pandora\Pandora;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\TriggerType;
use Pandora\Runs\Run;

/**
 * Where a message from a stranger meets the rest of Pandora, or does not.
 *
 * The order of the checks below is the security design, and it is worth reading
 * as an order rather than as a list. Tenancy is fixed by the account before
 * anything else looks at the payload; the payload is deduplicated before
 * anything acts on it; and the link is checked before a session, a conversation
 * or a run exists. Nothing about the sender is inferred at any point -- the only
 * question asked of the identity is whether a human previously linked it, and
 * the only answers are yes and no (ADR-0015).
 *
 * An unlinked identity gets no run and no session, not even an empty one. The
 * middle option -- a guest seat with no abilities -- reads as the cautious
 * choice and is not: a session is history, cost and context, and an anonymous
 * one is either shared between strangers (T3) or minted per stranger.
 */
final class ChannelInbox
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ChannelRegistry $channels,
        private readonly ChannelDispatcher $dispatcher,
        private readonly LinkCodes $linkCodes,
        private readonly ConversationManager $conversations,
        private readonly TenantManager $tenants,
        private readonly ActorManager $actors,
        private readonly AuditLogger $audit,
        private readonly Pandora $pandora,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
    ) {}

    public function receive(InboundMessage $message): InboundResult
    {
        $account = $this->account($message);

        if ($account === null) {
            // No account claims this workspace at all. Not audited, because
            // there is no tenant to audit it against -- and inventing one from
            // the payload is the exact move this module refuses to make. An
            // account that EXISTS and is switched off is a different case, and
            // it is handled below where a tenant is known.
            return InboundResult::refused('No channel account matches this message.');
        }

        $tenant = $account->tenant_id === null ? null : new TenantContext($account->tenant_id);

        /** @var InboundResult */
        return $this->tenants->with($tenant, fn (): InboundResult => $this->handle($account, $message));
    }

    private function handle(ChannelAccount $account, InboundMessage $message): InboundResult
    {
        if (! $account->enabled) {
            // Recorded without an identity, deliberately. A switched-off account
            // must not accumulate rows about people it is not talking to, and
            // the delivery row is still enough to count how much traffic arrived
            // while it was off -- which is what an operator wants to know before
            // switching it back on.
            $delivery = $this->recordInbound($account, null, $message);

            if ($delivery !== null) {
                $this->refuse($delivery, 'account_disabled');
            }

            return InboundResult::refused('This channel account is disabled.');
        }

        $identity = $this->identity($account, $message);

        $delivery = $this->recordInbound($account, $identity, $message);

        if ($delivery === null) {
            // The unique index refused it: this exact message has been handled
            // before. Slack retries on any slow response, so a duplicate is an
            // ordinary Tuesday rather than an attack, and the first outcome
            // stands.
            return InboundResult::duplicate($identity);
        }

        if (! $identity->isLinked()) {
            return $this->handleUnlinked($account, $identity, $message, $delivery);
        }

        $actor = $identity->actor();

        if ($actor === null) {
            // Linked to a user that no longer resolves -- deleted, or a model
            // class that moved. The link is stale, and a stale link loses
            // access rather than keeping it.
            $this->refuse($delivery, 'linked_user_missing');

            return InboundResult::unlinked($identity);
        }

        $agent = $account->agent;

        if (! $agent instanceof Agent) {
            $this->refuse($delivery, 'no_agent_bound');

            return InboundResult::refused('No agent is bound to this channel account.');
        }

        $conversation = $this->conversation($account, $identity, $agent, $actor->name);

        // A run that asked something is owed an answer, not a competitor. The
        // web chat has always done this; a channel that did not left the parked
        // run holding a question forever while every later message started a
        // fresh run beside it.
        $waiting = $this->waitingRun($conversation);

        if ($waiting !== null) {
            $run = $this->actors->with(
                $actor,
                fn (): Run => $this->pandora->reply($waiting, $message->text),
            );

            $delivery->forceFill([
                'identity_id' => $identity->getKey(),
                'run_id' => $run->getKey(),
                'status' => DeliveryStatus::Received,
            ])->save();

            $this->audit->record(
                action: 'channel.message_received',
                targetType: ChannelAccount::class,
                targetId: (string) $account->getKey(),
                runId: (string) $run->getKey(),
                metadata: [
                    'channel' => $account->channel,
                    'identity_id' => $identity->getKey(),
                    // Distinguishes an answer from a new request in the audit
                    // trail, where they otherwise look identical.
                    'resumed_run' => true,
                ],
            );

            return InboundResult::accepted($identity, $run);
        }

        $run = $this->actors->with($actor, fn () => $this->pandora
            ->agent($agent)
            ->forActor($actor)
            ->inConversation($conversation)
            ->viaChannel($account->channel, $identity->participantKey())
            ->triggeredBy(TriggerType::Channel)
            ->idempotencyKey($this->idempotencyKey($account, $message))
            ->withContext([
                'channel' => [
                    'key' => $account->channel,
                    'account' => $account->slug,
                    // Recorded for attribution, and deliberately not for
                    // authorization: nothing downstream may consult these to
                    // decide what the run may do.
                    'participant_external_id' => $identity->external_id,
                    'participant_display_name' => $identity->display_name,
                    // Where the reply goes. Carried from the inbound message so
                    // an answer lands in the thread that asked, and never
                    // anywhere the run itself could nominate.
                    'conversation_external_id' => $message->conversationExternalId,
                    'reply_to_external_id' => $message->externalMessageId,
                ],
            ])
            ->dispatch($message->text));

        $delivery->forceFill([
            'identity_id' => $identity->getKey(),
            'run_id' => $run->getKey(),
            'status' => DeliveryStatus::Received,
        ])->save();

        $this->audit->record(
            action: 'channel.message_received',
            targetType: ChannelAccount::class,
            targetId: (string) $account->getKey(),
            runId: (string) $run->getKey(),
            metadata: [
                'channel' => $account->channel,
                'identity_id' => $identity->getKey(),
            ],
        );

        return InboundResult::accepted($identity, $run);
    }

    /**
     * The only two things an unlinked participant can cause: a code, or a
     * refusal that tells them how to ask for one.
     */
    private function handleUnlinked(
        ChannelAccount $account,
        ChannelIdentity $identity,
        InboundMessage $message,
        ChannelDelivery $delivery,
    ): InboundResult {
        /** @var string $command */
        $command = $this->config->get('pandora.channels.linking.command', 'link');

        if ($message->normalisedText() === mb_strtolower($command)) {
            try {
                $code = $this->linkCodes->issue($identity);
            } catch (ChannelLinkDenied $e) {
                $this->refuse($delivery, $e->reason);
                $this->reply($account, $identity, $message, $e->getMessage());

                return InboundResult::unlinked($identity);
            }

            $this->refuse($delivery, 'link_code_issued');
            $this->reply($account, $identity, $message, $this->codeMessage($code));

            return InboundResult::linkCodeIssued($identity);
        }

        $this->refuse($delivery, 'identity_not_linked');

        // Answered once per window. A stranger messaging repeatedly must not be
        // able to turn our instructions into a flood aimed at their own channel,
        // and the refusal itself is already recorded whether or not we speak.
        $key = 'pandora:channel-refusal:'.$identity->getKey();

        if (! $this->limiter->tooManyAttempts($key, 1)) {
            $this->limiter->hit($key, (int) $this->config->get(
                'pandora.channels.linking.instruction_interval_seconds', 600,
            ));

            $this->reply($account, $identity, $message, $this->instructions($command));
        }

        return InboundResult::unlinked($identity);
    }

    /**
     * Find the account this message belongs to.
     *
     * Read across tenants, because a webhook arrives with no tenant resolved
     * and the account is the thing that decides which one it is. This is the
     * one deliberate cross-tenant read in the module, and it is a lookup on
     * `(channel, external_id)` -- a pair the remote system controls but that
     * only ever selects a row an operator created.
     *
     * Whether the account is USABLE is not decided here. A disabled account and
     * an unbound one are refusals that belong inside the account's own tenant,
     * where they can be recorded and audited; refusing them out here would make
     * them invisible, which is the shape of defect that costs an afternoon.
     */
    private function account(InboundMessage $message): ?ChannelAccount
    {
        if (! $this->channels->has($message->channelKey)) {
            return null;
        }

        /** @var ChannelAccount|null $account */
        $account = ChannelAccount::acrossAllTenants()
            ->where('channel', $message->channelKey)
            ->where('external_id', $message->accountExternalId)
            ->first();

        return $account;
    }

    private function identity(ChannelAccount $account, InboundMessage $message): ChannelIdentity
    {
        /** @var ChannelIdentity $identity */
        $identity = ChannelIdentity::query()->firstOrCreate(
            [
                'account_id' => $account->getKey(),
                'external_id' => $message->participantExternalId,
            ],
            [
                'tenant_id' => $account->tenant_id,
                'display_name' => $this->bounded($message->participantDisplayName),
            ],
        );

        // The display name is refreshed because people rename themselves, and
        // it is bounded because it is a string a stranger chose that will be
        // rendered on an operator's page.
        $identity->forceFill([
            'display_name' => $this->bounded($message->participantDisplayName) ?? $identity->display_name,
            'last_seen_at' => Carbon::now(),
        ])->save();

        return $identity;
    }

    /**
     * Claim this message, or discover somebody already has.
     *
     * The unique index does the deciding. Checking for an existing row first
     * and inserting second would leave a window two concurrent webhook
     * deliveries fit through comfortably, and the symptom would be a duplicated
     * answer rather than an error.
     */
    private function recordInbound(
        ChannelAccount $account,
        ?ChannelIdentity $identity,
        InboundMessage $message,
    ): ?ChannelDelivery {
        try {
            return $this->connection->transaction(fn (): ChannelDelivery => ChannelDelivery::query()->create([
                'tenant_id' => $account->tenant_id,
                'account_id' => $account->getKey(),
                'identity_id' => $identity?->getKey(),
                'direction' => DeliveryDirection::Inbound,
                'external_message_id' => $message->externalMessageId,
                'status' => DeliveryStatus::Received,
            ]));
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Record a refusal, and say so where an operator will look.
     *
     * Both halves matter. The delivery row is the count -- "this person has
     * been refused eleven times" -- and the audit entry is the explanation. A
     * refusal that is correct, bounded and invisible is the defect Phase 6's
     * walkthrough found in delegation, and it costs an afternoon every time.
     */
    private function refuse(ChannelDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::Refused,
            'error' => $reason,
        ])->save();

        // The link-code path is not a refusal an operator needs to see: it is
        // the flow working.
        if ($reason === 'link_code_issued') {
            return;
        }

        $this->audit->record(
            action: 'channel.message_refused',
            targetType: ChannelIdentity::class,
            targetId: $delivery->identity_id,
            severity: 'warning',
            metadata: [
                'account_id' => $delivery->account_id,
                'reason' => $reason,
            ],
        );
    }

    private function reply(
        ChannelAccount $account,
        ChannelIdentity $identity,
        InboundMessage $message,
        string $text,
    ): void {
        $this->dispatcher->send(new OutboundMessage(
            account: $account,
            identity: $identity,
            text: $text,
            conversationExternalId: $message->conversationExternalId,
            replyToExternalId: $message->externalMessageId,
        ));
    }

    /**
     * The conversation this identity is having, created on first use.
     *
     * Held on the identity rather than derived, and cleared whenever the link
     * changes, so a re-linked handle cannot walk into the previous holder's
     * transcript.
     */
    private function conversation(
        ChannelAccount $account,
        ChannelIdentity $identity,
        Agent $agent,
        ?string $actorName,
    ): Conversation {
        if ($identity->conversation_id !== null) {
            /** @var Conversation|null $existing */
            $existing = Conversation::query()->find($identity->conversation_id);

            if ($existing !== null) {
                return $existing;
            }
        }

        $conversation = $this->conversations->start(
            agent: $agent,
            actor: $identity->actor(),
            title: trim(($actorName ?? $identity->display_name ?? 'Channel').' on '.$account->name),
            channel: $account->channel,
        );

        $identity->forceFill(['conversation_id' => $conversation->getKey()])->save();

        return $conversation;
    }

    /**
     * The run on this conversation that is waiting to be answered, if any.
     *
     * Only `waiting_for_user` qualifies. A run waiting for an approval is not
     * the participant's to unblock -- ADR-0015 keeps that decision in the
     * control center -- so a message arriving during one starts a new run, as
     * it always has.
     */
    private function waitingRun(Conversation $conversation): ?Run
    {
        /** @var Run|null $run */
        $run = $conversation->runs()
            ->where('state', RunState::WaitingForUser->value)
            ->latest('created_at')
            ->first();

        return $run;
    }

    private function idempotencyKey(ChannelAccount $account, InboundMessage $message): string
    {
        return 'channel:'.$account->getKey().':'.($message->externalMessageId ?? uniqid('', true));
    }

    private function codeMessage(string $code): string
    {
        /** @var string|null $url */
        $url = $this->config->get('pandora.channels.linking.redeem_url');

        return "Your linking code is {$code}. Sign in to "
            .($url ?? 'the application')
            .' and enter it there to connect this account. It expires shortly and works once.';
    }

    private function instructions(string $command): string
    {
        return "This account is not linked to a user yet, so I cannot act on it. Send \"{$command}\" "
            .'and I will give you a code to enter while signed in.';
    }

    private function bounded(?string $value, int $length = 191): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
