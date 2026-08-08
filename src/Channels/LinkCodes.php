<?php

declare(strict_types=1);

namespace Pandora\Channels;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\ChannelLinkDenied;

/**
 * Issues and redeems the codes that turn a channel participant into a host
 * user.
 *
 * The direction is the decision, and it is the non-obvious part of ADR-0015.
 * The code goes OUT through the channel, privately, to the participant who
 * asked -- that proves control of the channel account. It comes back IN through
 * an authenticated host session -- that proves control of the host account.
 * Linking is the claim that those are the same person, so it needs both halves
 * and nothing weaker. Either half alone links the wrong person: a code an
 * operator generates for a named user is a bearer token for that user, and a
 * mapping an administrator types is a record of their belief about who owns a
 * Slack handle.
 *
 * The redeeming user is taken from the authenticated session and never from the
 * request. There is no `user_id` parameter anywhere in this class, which is why
 * a redemption naming somebody else links nobody.
 */
final class LinkCodes
{
    /** Unambiguous alphabet: no O/0, no I/1/L. Codes get read aloud and retyped. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
    ) {}

    /**
     * Mint a code for an identity and return the plaintext, once.
     *
     * The caller's only correct use of the return value is to send it back
     * into the channel, to that participant. It is never stored, logged or
     * shown in the control center: the row keeps a hash, so the plaintext
     * exists in exactly one message and nowhere else.
     */
    public function issue(ChannelIdentity $identity): string
    {
        if ($identity->isLinked()) {
            throw ChannelLinkDenied::alreadyLinked();
        }

        $this->hit(
            'pandora:channel-link-issue:'.$identity->getKey(),
            (int) $this->config->get('pandora.channels.linking.max_codes_per_hour', 5),
        );

        $code = $this->generate();

        $this->connection->transaction(function () use ($identity, $code): void {
            // Asking for a new code invalidates the last one. Two live codes
            // for one identity is two chances for a stale message in a channel
            // scrollback to still work.
            ChannelLinkCode::query()
                ->where('identity_id', $identity->getKey())
                ->whereNull('consumed_at')
                ->update(['expires_at' => Carbon::now()->subSecond()]);

            ChannelLinkCode::query()->create([
                'tenant_id' => $identity->tenant_id,
                'identity_id' => $identity->getKey(),
                'code_hash' => $this->hash($code),
                'expires_at' => Carbon::now()->addSeconds(
                    (int) $this->config->get('pandora.channels.linking.code_ttl_seconds', 900),
                ),
            ]);
        });

        $this->audit->record(
            action: 'channel.link_code_issued',
            targetType: ChannelIdentity::class,
            targetId: (string) $identity->getKey(),
            metadata: ['account_id' => $identity->account_id],
        );

        return $code;
    }

    /**
     * Redeem a code as the authenticated user, and link the identity to them.
     *
     * `$user` must come from the guard. The whole security property of this
     * method is that its caller cannot choose who gets linked.
     */
    public function redeem(string $code, Authorizable $user): ChannelIdentity
    {
        /** @var Model&Authorizable $user */
        $userKey = $user::class.':'.$user->getKey();

        $this->hit(
            'pandora:channel-link-redeem:'.sha1($userKey),
            (int) $this->config->get('pandora.channels.linking.max_attempts_per_hour', 10),
        );

        $normalised = $this->normalise($code);

        /** @var ChannelLinkCode|null $record */
        $record = ChannelLinkCode::query()
            ->where('code_hash', $this->hash($normalised))
            ->first();

        // Tenant scoping is deliberately left to the global scope. A code minted
        // under one tenant is invisible to a session in another, so a user
        // cannot redeem their way into somebody else's installation even
        // holding a valid string.
        if ($record === null || ! $record->isRedeemable()) {
            $this->recordFailure($record?->identity_id, $record === null ? 'unknown_code' : 'expired_or_used');

            throw ChannelLinkDenied::invalidCode();
        }

        /** @var ChannelIdentity|null $identity */
        $identity = ChannelIdentity::query()->find($record->identity_id);

        if ($identity === null) {
            $this->recordFailure($record->identity_id, 'unknown_identity');

            throw ChannelLinkDenied::invalidCode();
        }

        if ($identity->isLinked()) {
            $this->recordFailure((string) $identity->getKey(), 'already_linked');

            throw ChannelLinkDenied::alreadyLinked();
        }

        $this->connection->transaction(function () use ($record, $identity, $user): void {
            /** @var Model&Authorizable $user */
            $record->forceFill([
                'consumed_at' => Carbon::now(),
                'redeemed_by_type' => $user::class,
                'redeemed_by_id' => (string) $user->getKey(),
            ])->save();

            $identity->forceFill([
                'linked_user_type' => $user::class,
                'linked_user_id' => (string) $user->getKey(),
                'linked_at' => Carbon::now(),
                // A new epoch, so this link starts a fresh isolation boundary
                // rather than inheriting whatever the previous holder said.
                'link_epoch' => $identity->link_epoch + 1,
                'conversation_id' => null,
            ])->save();
        });

        $this->audit->record(
            action: 'channel.identity_linked',
            targetType: ChannelIdentity::class,
            targetId: (string) $identity->getKey(),
            metadata: [
                'account_id' => $identity->account_id,
                'external_id' => $identity->external_id,
            ],
        );

        return $identity->refresh();
    }

    /**
     * Break a link, from either side.
     *
     * Immediate and not retroactive: the conversation that happened, happened,
     * and the next inbound message is refused. The identity keeps its history
     * of having been linked; only the pointer goes.
     */
    public function unlink(ChannelIdentity $identity, string $reason = 'operator'): ChannelIdentity
    {
        $previousUser = $identity->linked_user_id;

        $this->connection->transaction(function () use ($identity): void {
            $identity->forceFill([
                'linked_user_type' => null,
                'linked_user_id' => null,
                'linked_at' => null,
                // The conversation goes with the link. A re-link gets a new
                // epoch and therefore a new session; leaving the old
                // conversation attached would hand it to whoever links next.
                'conversation_id' => null,
            ])->save();

            ChannelLinkCode::query()
                ->where('identity_id', $identity->getKey())
                ->whereNull('consumed_at')
                ->update(['expires_at' => Carbon::now()->subSecond()]);
        });

        $this->audit->record(
            action: 'channel.identity_unlinked',
            targetType: ChannelIdentity::class,
            targetId: (string) $identity->getKey(),
            metadata: ['reason' => $reason, 'previous_user_id' => $previousUser],
        );

        return $identity->refresh();
    }

    private function recordFailure(?string $identityId, string $reason): void
    {
        $this->audit->record(
            action: 'channel.link_failed',
            targetType: ChannelIdentity::class,
            targetId: $identityId,
            severity: 'warning',
            metadata: ['reason' => $reason],
        );
    }

    /**
     * @throws ChannelLinkDenied
     */
    private function hit(string $key, int $perHour): void
    {
        if ($this->limiter->tooManyAttempts($key, $perHour)) {
            throw ChannelLinkDenied::rateLimited();
        }

        $this->limiter->hit($key, 3600);
    }

    private function generate(): string
    {
        $length = (int) $this->config->get('pandora.channels.linking.code_length', 8);
        $alphabet = self::ALPHABET;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function normalise(string $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /**
     * Keyed, so the hash is not searchable offline without the application key.
     *
     * The code space is small enough to enumerate — that is the price of a
     * string a person retypes — so the defence is short expiry, single use,
     * rate limiting, and a hash an attacker with the database alone cannot
     * invert by iteration.
     */
    private function hash(string $code): string
    {
        /** @var string $key */
        $key = $this->config->get('app.key', '');

        return hash_hmac('sha256', $this->normalise($code), $key);
    }
}
