<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Channels\ChannelDelivery;
use Pandora\Channels\ChannelInbox;
use Pandora\Channels\Enums\DeliveryStatus;
use Pandora\Runs\Run;
use Pandora\Testing\FakeChannel;
use Pandora\Tests\Support\MakesChannels;

/**
 * Phase 8, criteria 16 and 22 — an answer goes back where the question came
 * from, or nowhere.
 *
 * The rule being defended is negative and easy to erode: a reply that cannot be
 * delivered is never delivered somewhere else. No fallback channel, no email
 * copy, no second account. Improvising a delivery route is the kind of change
 * that reads as helpfulness in review and is a disclosure in production.
 *
 * So a failure has to be visible instead, which is what the delivery row and the
 * warning-severity audit entry are for.
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    $this->account = $this->makeChannelAccount();
    $this->inbox = app(ChannelInbox::class);
});

it('delivers the answer back to the conversation it came from', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-1', $user);

    $this->fakeProvider()->willRespondWith('The order shipped on Tuesday.');

    $channel = $this->fakeChannel();
    $channel->forget();

    $this->inbox->receive($channel->message('U-1', 'When did it ship?', conversationExternalId: 'C-42'));

    $sent = $channel->sent();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->text)->toBe('The order shipped on Tuesday.')
        ->and($sent[0]->conversationExternalId)->toBe('C-42');
});

it('records a delivery row for the reply', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-1', $user);

    $this->fakeProvider()->willRespondWith('Fine.');

    $this->inbox->receive($this->fakeChannel()->message('U-1', 'hello'));

    $outbound = ChannelDelivery::query()->where('direction', 'outbound')->firstOrFail();

    expect($outbound->status)->toBe(DeliveryStatus::Sent)
        ->and($outbound->run_id)->toBe((string) Run::query()->firstOrFail()->getKey());
});

it('records a failure rather than re-routing it', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-1', $user);

    $other = $this->makeChannelAccount(['external_id' => 'other-workspace']);

    $this->fakeProvider()->willRespondWith('Fine.');

    $this->fakeChannel()->fails('Slack returned 503.');

    $this->inbox->receive($this->fakeChannel()->message('U-1', 'hello'));

    $outbound = ChannelDelivery::query()->where('direction', 'outbound')->firstOrFail();

    expect($outbound->status)->toBe(DeliveryStatus::Failed)
        ->and($outbound->error)->toBe('Slack returned 503.')
        // Nothing went anywhere near the other account.
        ->and($other->deliveries()->count())->toBe(0);
});

it('audits a delivery failure at warning severity', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-1', $user);

    $this->fakeProvider()->willRespondWith('Fine.');
    $this->fakeChannel()->fails();

    $this->inbox->receive($this->fakeChannel()->message('U-1', 'hello'));

    expect(AuditLog::query()->where('action', 'channel.delivery_failed')->firstOrFail()->severity)
        ->toBe('warning');
});

it('survives an adapter that throws', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-1', $user);

    $this->fakeProvider()->willRespondWith('Fine.');

    // Third-party code, third-party exceptions. DNS goes and a channel adapter
    // throws whatever its HTTP client throws; a worker must not die of it.
    $this->fakeChannel()->throws();

    $this->inbox->receive($this->fakeChannel()->message('U-1', 'hello'));

    $outbound = ChannelDelivery::query()->where('direction', 'outbound')->firstOrFail();

    expect($outbound->status)->toBe(DeliveryStatus::Failed)
        ->and($outbound->error)->toContain('RuntimeException');
});

it('sends nothing through an adapter nobody registered', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $orphan = $this->makeChannelAccount([
        'channel' => 'removed-extension',
        'external_id' => 'orphan-workspace',
    ]);

    $this->makeIdentity($orphan, 'U-1', $user);

    // The extension that provided the adapter has been removed; the row
    // outlived it. That is an inventory state, not a fatal.
    $result = $this->inbox->receive(
        (new FakeChannel('removed-extension'))->message('U-1', 'hello', 'orphan-workspace'),
    );

    expect($result->outcome->value)->toBe('refused')
        ->and(Run::query()->count())->toBe(0);
});
