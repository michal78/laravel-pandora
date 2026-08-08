<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Channels\ChannelInbox;
use Pandora\Channels\Data\InboundOutcome;
use Pandora\Channels\LinkCodes;
use Pandora\Conversations\Session;
use Pandora\Exceptions\ChannelLinkDenied;
use Pandora\Messages\Message;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesChannels;

/**
 * Phase 8, criteria 8 and 9 — unlinking is immediate, and re-linking starts
 * over.
 *
 * The second half is the one that is easy to get wrong by doing nothing. A
 * Slack handle is reassigned when somebody leaves; if the new holder links it
 * and inherits the previous holder's conversation, that is a disclosure with no
 * attacker in it, discovered a year later by somebody scrolling up. The link
 * epoch is in the session key precisely so that a new link is a new boundary.
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    $this->account = $this->makeChannelAccount();
    $this->inbox = app(ChannelInbox::class);
    $this->codes = app(LinkCodes::class);
});

it('refuses the next message immediately after unlinking', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $identity = $this->makeIdentity($this->account, 'U-1', $user);

    $this->fakeProvider()->willRespondWith('Sure.');
    $channel = $this->fakeChannel();

    expect($this->inbox->receive($channel->message('U-1', 'hello'))->outcome)
        ->toBe(InboundOutcome::Accepted);

    $this->codes->unlink($identity);

    expect($this->inbox->receive($channel->message('U-1', 'hello again'))->outcome)
        ->toBe(InboundOutcome::Unlinked)
        ->and(Run::query()->count())->toBe(1);
});

it('audits the unlink', function (): void {
    $user = $this->actingAsUser();
    $identity = $this->makeIdentity($this->account, 'U-1', $user);

    $this->codes->unlink($identity, 'operator');

    $log = AuditLog::query()->where('action', 'channel.identity_unlinked')->firstOrFail();

    expect($log->metadata['reason'] ?? null)->toBe('operator')
        ->and($log->metadata['previous_user_id'] ?? null)->toBe((string) $user->getKey());
});

it('gives a re-linked identity a new isolation key and no prior history', function (): void {
    $departed = $this->actingAsUser();
    app('auth')->logout();

    $identity = $this->makeIdentity($this->account, 'U-shared-handle', $departed);

    $this->fakeProvider()->willRespondWith('Understood.');
    $channel = $this->fakeChannel();

    $this->inbox->receive($channel->message('U-shared-handle', 'The passphrase is hunter2.'));

    $firstKey = Session::query()->firstOrFail()->isolation_key;

    // The employee leaves; the handle is reassigned.
    $this->codes->unlink($identity, 'deprovisioned');

    $successor = $this->actingAsUser();
    app('auth')->logout();

    $code = $this->codes->issue($identity->fresh());
    $this->codes->redeem($code, $successor);

    $this->fakeProvider()->willRespondWith('I have no idea.');

    $run = $this->inbox->receive($channel->message('U-shared-handle', 'What is the passphrase?'))->run;

    expect($run)->not->toBeNull();

    $session = Session::query()->findOrFail($run->session_id);

    expect($session->isolation_key)->not->toBe($firstKey)
        ->and($session->actor_id)->toBe((string) $successor->getKey());

    $visible = Message::query()
        ->where('conversation_id', $run->conversation_id)
        ->pluck('content')
        ->implode("\n");

    expect($visible)->not->toContain('hunter2');
});

it('bumps the link epoch on every link', function (): void {
    $first = $this->actingAsUser();
    app('auth')->logout();

    $identity = $this->makeIdentity($this->account, 'U-1');

    expect($identity->link_epoch)->toBe(0);

    $this->codes->redeem($this->codes->issue($identity), $first);
    expect($identity->fresh()->link_epoch)->toBe(1);

    $this->codes->unlink($identity->fresh());

    $second = $this->actingAsUser();
    app('auth')->logout();

    $this->codes->redeem($this->codes->issue($identity->fresh()), $second);
    expect($identity->fresh()->link_epoch)->toBe(2);
});

it('kills any live code when a link is broken', function (): void {
    $user = $this->actingAsUser();
    $identity = $this->makeIdentity($this->account, 'U-1');

    $code = $this->codes->issue($identity);

    $this->codes->unlink($identity);

    expect(fn () => $this->codes->redeem($code, $user))
        ->toThrow(ChannelLinkDenied::class);
});
