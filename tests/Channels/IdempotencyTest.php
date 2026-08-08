<?php

declare(strict_types=1);

use Pandora\Channels\ChannelDelivery;
use Pandora\Channels\ChannelInbox;
use Pandora\Channels\Data\InboundOutcome;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesChannels;

/**
 * Phase 8, criterion 15 — a retry produces one run.
 *
 * Every messaging platform retries a webhook that answers slowly, and an agent
 * run is exactly the kind of work that answers slowly. Without a guard the
 * symptom is not an error: it is the agent replying twice, or acting twice,
 * which is worse the more useful the tool is.
 *
 * The guard is the unique index rather than a read-then-write, because two
 * concurrent deliveries fit through a check-first window comfortably.
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    $this->account = $this->makeChannelAccount();
    $this->inbox = app(ChannelInbox::class);
});

it('creates one run for a redelivered message', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-1', $user);

    $this->fakeProvider()->willRespondWith('Once.');

    $channel = $this->fakeChannel();
    $message = $channel->message('U-1', 'Refund order 42.', externalMessageId: 'slack-evt-1');

    $first = $this->inbox->receive($message);
    $second = $this->inbox->receive($message);

    expect($first->outcome)->toBe(InboundOutcome::Accepted)
        ->and($second->outcome)->toBe(InboundOutcome::Duplicate)
        ->and(Run::query()->count())->toBe(1);
});

it('treats two different messages as two', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-1', $user);

    $this->fakeProvider()
        ->willRespondWith('One.')
        ->willRespondWith('Two.');

    $channel = $this->fakeChannel();

    $this->inbox->receive($channel->message('U-1', 'First.'));
    $this->inbox->receive($channel->message('U-1', 'Second.'));

    expect(Run::query()->count())->toBe(2);
});

it('deduplicates per account, so two workspaces can mint the same id', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $other = $this->makeChannelAccount(['external_id' => 'other-workspace']);

    $this->makeIdentity($this->account, 'U-1', $user);
    $this->makeIdentity($other, 'U-1', $user);

    $this->fakeProvider()
        ->willRespondWith('One.')
        ->willRespondWith('Two.');

    $channel = $this->fakeChannel();

    $this->inbox->receive($channel->message('U-1', 'hello', externalMessageId: 'evt-1'));
    $this->inbox->receive($channel->message('U-1', 'hello', 'other-workspace', 'evt-1'));

    // Nothing says two remote systems agree on an id space, and a global
    // uniqueness rule would silently swallow the second workspace's traffic.
    expect(Run::query()->count())->toBe(2);
});

it('refuses a duplicate even when the first was a refusal', function (): void {
    $channel = $this->fakeChannel();
    $message = $channel->message('U-stranger', 'hello', externalMessageId: 'evt-1');

    $this->inbox->receive($message);
    $second = $this->inbox->receive($message);

    expect($second->outcome)->toBe(InboundOutcome::Duplicate)
        ->and(ChannelDelivery::query()->where('direction', 'inbound')->count())->toBe(1);
});
