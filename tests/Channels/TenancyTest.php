<?php

declare(strict_types=1);

use Pandora\Channels\ChannelAccount;
use Pandora\Channels\ChannelIdentity;
use Pandora\Channels\ChannelInbox;
use Pandora\Channels\Data\InboundMessage;
use Pandora\Channels\Data\InboundOutcome;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesChannels;

/**
 * Phase 8, criteria 11 and 12 — the account decides the tenant, and nothing
 * else gets a vote.
 *
 * An inbound webhook arrives with no tenant resolved, so something has to
 * decide. The candidates are the payload and the account row; the payload is
 * the least trustworthy thing in the request, so it is the account, written by
 * an operator, and every identity, run and delivery beneath it inherits that.
 */
uses(MakesChannels::class);

it('takes the tenant from the account, not the payload', function (): void {
    $user = inTenant('acme', function () {
        $user = $this->actingAsUser();
        app('auth')->logout();

        return $user;
    });

    $account = inTenant('acme', fn () => $this->makeChannelAccount(['external_id' => 'acme-workspace']));

    inTenant('acme', fn () => $this->makeIdentity($account, 'U-1', $user));

    $this->fakeProvider()->willRespondWith('Fine.');

    // Received with NO tenant resolved -- exactly as a webhook arrives.
    $result = app(ChannelInbox::class)->receive(new InboundMessage(
        channelKey: $this->fakeChannel()->key(),
        accountExternalId: 'acme-workspace',
        participantExternalId: 'U-1',
        text: 'hello',
        externalMessageId: 'm-1',
    ));

    expect($result->outcome)->toBe(InboundOutcome::Accepted);

    $run = Run::query()->withoutGlobalScope('pandora_tenant')->firstOrFail();

    expect($run->tenant_id)->toBe('acme');
});

it('cannot be steered into another tenant by anything in the message', function (): void {
    $user = inTenant('acme', function () {
        $user = $this->actingAsUser();
        app('auth')->logout();

        return $user;
    });

    $acme = inTenant('acme', fn () => $this->makeChannelAccount(['external_id' => 'acme-workspace']));
    inTenant('acme', fn () => $this->makeIdentity($acme, 'U-1', $user));

    inTenant('globex', fn () => $this->makeChannelAccount(['external_id' => 'globex-workspace']));

    $this->fakeProvider()->willRespondWith('Fine.');

    app(ChannelInbox::class)->receive(new InboundMessage(
        channelKey: $this->fakeChannel()->key(),
        accountExternalId: 'acme-workspace',
        participantExternalId: 'U-1',
        text: 'hello',
        externalMessageId: 'm-1',
        // A payload doing its best to nominate a tenant. Nothing reads it.
        raw: ['tenant_id' => 'globex', 'tenant' => 'globex', 'team_domain' => 'globex'],
    ));

    $run = Run::query()->withoutGlobalScope('pandora_tenant')->firstOrFail();

    expect($run->tenant_id)->toBe('acme');
});

it('hides another tenant accounts and identities', function (): void {
    $acme = inTenant('acme', fn () => $this->makeChannelAccount(['external_id' => 'acme-workspace']));
    inTenant('acme', fn () => $this->makeIdentity($acme, 'U-1'));

    inTenant('globex', function (): void {
        expect(ChannelAccount::query()->count())->toBe(0)
            ->and(ChannelIdentity::query()->count())->toBe(0);
    });

    inTenant('acme', function (): void {
        expect(ChannelAccount::query()->count())->toBe(1)
            ->and(ChannelIdentity::query()->count())->toBe(1);
    });
});

it('refuses a message for a workspace no account claims', function (): void {
    $result = app(ChannelInbox::class)->receive(new InboundMessage(
        channelKey: $this->fakeChannel()->key(),
        accountExternalId: 'somebody-elses-workspace',
        participantExternalId: 'U-1',
        text: 'hello',
        externalMessageId: 'm-1',
    ));

    expect($result->outcome)->toBe(InboundOutcome::Refused)
        ->and(Run::query()->withoutGlobalScope('pandora_tenant')->count())->toBe(0);
});
