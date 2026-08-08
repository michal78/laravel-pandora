<?php

declare(strict_types=1);

use Pandora\Channels\ChannelInbox;
use Pandora\Conversations\Session;
use Pandora\Messages\Message;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesChannels;

/**
 * Phase 8, criterion 10 — two people in one channel are two sessions (T3).
 *
 * The session key has carried `(tenant, agent, actor, channel, participant,
 * origin)` since Phase 1, and until now the last three were effectively
 * constant. A shared Slack channel is the first thing that makes them
 * load-bearing: two colleagues messaging one agent must not be able to read
 * each other's history, and the boundary that stops it is the same one that
 * stops it on the web.
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    $this->account = $this->makeChannelAccount();
    $this->inbox = app(ChannelInbox::class);
});

it('gives two participants in one channel two sessions', function (): void {
    $alice = $this->actingAsUser();
    $bob = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-alice', $alice);
    $this->makeIdentity($this->account, 'U-bob', $bob);

    $this->fakeProvider()
        ->willRespondWith('Noted.')
        ->willRespondWith('Noted.');

    $channel = $this->fakeChannel();

    $this->inbox->receive($channel->message('U-alice', 'My salary is confidential.'));
    $this->inbox->receive($channel->message('U-bob', 'What did she just say?'));

    $sessions = Session::query()->get();

    expect($sessions)->toHaveCount(2)
        ->and($sessions->pluck('isolation_key')->unique())->toHaveCount(2);
});

it('keeps their conversations apart', function (): void {
    $alice = $this->actingAsUser();
    $bob = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-alice', $alice);
    $this->makeIdentity($this->account, 'U-bob', $bob);

    $this->fakeProvider()
        ->willRespondWith('Noted.')
        ->willRespondWith('Noted.');

    $channel = $this->fakeChannel();

    $this->inbox->receive($channel->message('U-alice', 'The passphrase is hunter2.'));
    $bobRun = $this->inbox->receive($channel->message('U-bob', 'What is the passphrase?'))->run;

    expect($bobRun)->not->toBeNull();

    $bobMessages = Message::query()
        ->where('conversation_id', $bobRun->conversation_id)
        ->pluck('content')
        ->implode("\n");

    expect($bobMessages)->not->toContain('hunter2');
});

it('gives one participant one session across several messages', function (): void {
    $alice = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-alice', $alice);

    $this->fakeProvider()
        ->willRespondWith('One.')
        ->willRespondWith('Two.');

    $channel = $this->fakeChannel();

    $this->inbox->receive($channel->message('U-alice', 'First.'));
    $this->inbox->receive($channel->message('U-alice', 'Second.'));

    expect(Session::query()->count())->toBe(1)
        ->and(Run::query()->count())->toBe(2);
});
