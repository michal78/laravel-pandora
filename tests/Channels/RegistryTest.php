<?php

declare(strict_types=1);

use Pandora\Channels\ChannelAccount;
use Pandora\Channels\ChannelRegistry;
use Pandora\Channels\Data\InboundMessage;
use Pandora\Contracts\Channel;
use Pandora\Core\Actor\ActorContext;
use Pandora\Exceptions\InvalidConfiguration;
use Pandora\Testing\FakeChannel;
use Pandora\Tests\Support\MakesChannels;

/**
 * Phase 8, criteria 20 and 21 — registering an adapter connects nothing, and the
 * contract has no field for the mistake.
 *
 * The second is the more interesting assertion and it is deliberately
 * structural. An extension author writing a Slack adapter knows the Slack user's
 * email; every instinct says to pass it along and let Pandora "resolve" it. The
 * contract's answer is that there is nowhere to put it — no actor parameter, no
 * user field, no return type that could carry one. A rule enforced by the shape
 * of an interface cannot be forgotten by an author who never read the ADR.
 */
uses(MakesChannels::class);

it('registers an adapter and connects nothing', function (): void {
    $registry = app(ChannelRegistry::class);
    $registry->register(new FakeChannel);

    expect($registry->has('fake'))->toBeTrue()
        ->and($registry->keys())->toContain('fake')
        // No account, so nothing can arrive and nothing can be sent.
        ->and(ChannelAccount::query()->count())->toBe(0);
});

it('creates a new account disabled and unbound', function (): void {
    $account = ChannelAccount::query()->create([
        'channel' => 'fake',
        'name' => 'Fresh',
        'slug' => 'fresh',
        'external_id' => 'W-1',
    ]);

    expect($account->enabled)->toBeFalse()
        ->and($account->agent_id)->toBeNull()
        ->and($account->isUsable())->toBeFalse();
});

it('refuses two adapters with the same key', function (): void {
    $registry = app(ChannelRegistry::class);
    $registry->register(new FakeChannel('slack'));

    expect(fn () => $registry->register(new FakeChannel('slack')))
        ->toThrow(InvalidConfiguration::class, 'registered twice');
});

it('returns null for an adapter nobody registered', function (): void {
    // Null rather than an exception: an account whose extension was removed is
    // an ordinary state, and an inventory page must still render.
    expect(app(ChannelRegistry::class)->get('never-installed'))->toBeNull();
});

it('gives an adapter no way to supply an actor', function (): void {
    $reflection = new ReflectionClass(Channel::class);

    foreach ($reflection->getMethods() as $method) {
        foreach ($method->getParameters() as $parameter) {
            expect($parameter->getName())
                ->not->toMatch('/user|actor|identity|email|principal/i');
        }

        $return = (string) $method->getReturnType();

        expect($return)
            ->not->toContain(ActorContext::class)
            ->not->toContain('Authorizable');
    }
});

it('gives an inbound message no field that names a host user', function (): void {
    $constructor = (new ReflectionClass(InboundMessage::class))->getConstructor();

    expect($constructor)->not->toBeNull();

    foreach ($constructor->getParameters() as $parameter) {
        // `participantExternalId` and `participantDisplayName` are allowed:
        // they identify somebody in SOMEBODY ELSE'S system, which is exactly
        // what a channel is entitled to assert.
        expect($parameter->getName())
            ->not->toMatch('/^(user|actor|email|tenant)/i')
            ->not->toMatch('/user_?id|actor_?id|tenant_?id/i');
    }
});
