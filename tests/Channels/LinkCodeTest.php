<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Pandora\Audit\AuditLog;
use Pandora\Channels\ChannelInbox;
use Pandora\Channels\ChannelLinkCode;
use Pandora\Channels\Data\InboundOutcome;
use Pandora\Channels\LinkCodes;
use Pandora\Exceptions\ChannelLinkDenied;
use Pandora\Tests\Support\MakesChannels;

/**
 * Phase 8, criteria 3, 5 and 6 — the code half of the linking evidence.
 *
 * A code proves control of the channel account and nothing else. So it goes out
 * through the channel, to the participant who asked; it expires; it works once;
 * and it is stored hashed, because a credential that grants an identity must
 * not be readable by anything that can read the table.
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    $this->account = $this->makeChannelAccount();
    $this->codes = app(LinkCodes::class);
});

it('issues a code into the channel when the participant asks', function (): void {
    $channel = $this->fakeChannel();

    $result = app(ChannelInbox::class)->receive($channel->message('U-1', 'link'));

    expect($result->outcome)->toBe(InboundOutcome::LinkCodeIssued)
        ->and($channel->sent())->toHaveCount(1)
        ->and($channel->lastText())->toContain('linking code');

    $code = ChannelLinkCode::query()->firstOrFail();

    expect($code->identity_id)->toBe((string) $result->identity?->getKey())
        ->and($code->isRedeemable())->toBeTrue();
});

it('stores the code hashed and never in plaintext', function (): void {
    $identity = $this->makeIdentity($this->account, 'U-1');

    $plaintext = $this->codes->issue($identity);

    $row = (array) ChannelLinkCode::query()->firstOrFail()->getAttributes();

    foreach ($row as $value) {
        expect(is_string($value) ? $value : '')->not->toContain($plaintext);
    }

    // And there is no column that could hold one.
    $columns = Schema::connection(config('pandora.database.connection'))
        ->getColumnListing((new ChannelLinkCode)->getTable());

    foreach ($columns as $column) {
        expect($column)->not->toMatch('/^code$|plain|secret/i');
    }
});

it('hides the hash from serialisation', function (): void {
    $identity = $this->makeIdentity($this->account, 'U-1');
    $this->codes->issue($identity);

    expect(ChannelLinkCode::query()->firstOrFail()->toArray())->not->toHaveKey('code_hash');
});

it('expires a code and refuses it afterwards', function (): void {
    $user = $this->actingAsUser();
    $identity = $this->makeIdentity($this->account, 'U-1');

    $code = $this->codes->issue($identity);

    $this->travel(16)->minutes();

    expect(fn () => $this->codes->redeem($code, $user))
        ->toThrow(ChannelLinkDenied::class);

    expect($identity->fresh()->isLinked())->toBeFalse();
});

it('works exactly once', function (): void {
    $first = $this->actingAsUser();
    $identity = $this->makeIdentity($this->account, 'U-1');

    $code = $this->codes->issue($identity);
    $this->codes->redeem($code, $first);

    $second = $this->actingAsUser();

    // Already consumed AND the identity is already linked. Either check alone
    // would refuse it; both exist because a code that survives redemption is a
    // second person's route into somebody else's account.
    expect(fn () => $this->codes->redeem($code, $second))
        ->toThrow(ChannelLinkDenied::class);

    expect($identity->fresh()->linked_user_id)->toBe((string) $first->getKey());
});

it('invalidates the previous code when a new one is asked for', function (): void {
    $user = $this->actingAsUser();
    $identity = $this->makeIdentity($this->account, 'U-1');

    $stale = $this->codes->issue($identity);
    $fresh = $this->codes->issue($identity);

    // A code left live in a channel scrollback is a code somebody finds later.
    expect(fn () => $this->codes->redeem($stale, $user))->toThrow(ChannelLinkDenied::class);

    $this->codes->redeem($fresh, $user);

    expect($identity->fresh()->isLinked())->toBeTrue();
});

it('audits a failed redemption at warning severity', function (): void {
    $user = $this->actingAsUser();

    expect(fn () => $this->codes->redeem('NOTACODE', $user))->toThrow(ChannelLinkDenied::class);

    $log = AuditLog::query()->where('action', 'channel.link_failed')->firstOrFail();

    expect($log->severity)->toBe('warning')
        ->and($log->metadata['reason'] ?? null)->toBe('unknown_code');
});

it('rate limits redemption attempts per user', function (): void {
    $user = $this->actingAsUser();

    for ($i = 0; $i < 10; $i++) {
        try {
            $this->codes->redeem('WRONG'.$i, $user);
        } catch (ChannelLinkDenied) {
            // expected
        }
    }

    expect(fn () => $this->codes->redeem('WRONGAGAIN', $user))
        ->toThrow(ChannelLinkDenied::class, 'Too many linking attempts.');
});

it('rate limits how many codes one identity can ask for', function (): void {
    $identity = $this->makeIdentity($this->account, 'U-1');

    for ($i = 0; $i < 5; $i++) {
        $this->codes->issue($identity);
    }

    expect(fn () => $this->codes->issue($identity))
        ->toThrow(ChannelLinkDenied::class, 'Too many linking attempts.');
});

it('refuses to issue a code for an identity that is already linked', function (): void {
    $user = $this->actingAsUser();
    $identity = $this->makeIdentity($this->account, 'U-1', $user);

    expect(fn () => $this->codes->issue($identity))
        ->toThrow(ChannelLinkDenied::class, 'already linked');
});
