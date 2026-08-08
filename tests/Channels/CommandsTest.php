<?php

declare(strict_types=1);

use Pandora\Tests\Support\MakesChannels;

/**
 * `pandora:channel:list` — the inventory for an operator with a terminal and no
 * control center.
 *
 * The assertion worth reading is the last one. "Seen" and "linked" are different
 * numbers and both have to be visible: nine people who messaged an agent and
 * were refused is correct behaviour, and it reads as an outage until the command
 * says how many of them ever linked.
 */
uses(MakesChannels::class);

it('says plainly when nothing is registered', function (): void {
    $this->fakeChannel();

    $this->artisan('pandora:channel:list')
        ->expectsOutputToContain('No channel accounts are registered.')
        ->expectsOutputToContain('Installed adapters: fake')
        ->assertSuccessful();
});

it('lists an account with its workspace and agent', function (): void {
    $account = $this->makeChannelAccount(['name' => 'Acme Slack']);

    $this->artisan('pandora:channel:list')
        ->expectsOutputToContain('Acme Slack')
        ->expectsOutputToContain($account->external_id)
        ->expectsOutputToContain($account->agent?->slug ?? '')
        ->assertSuccessful();
});

it('flags an account whose adapter is not installed', function (): void {
    $this->makeChannelAccount(['channel' => 'removed-extension', 'external_id' => 'W-orphan']);

    $this->artisan('pandora:channel:list')
        ->expectsOutputToContain('no adapter installed')
        ->assertSuccessful();
});

it('counts identities seen and identities linked separately', function (): void {
    $user = $this->actingAsUser();
    $account = $this->makeChannelAccount();

    $this->makeIdentity($account, 'U-1', $user);
    $this->makeIdentity($account, 'U-2');
    $this->makeIdentity($account, 'U-3');

    $this->artisan('pandora:channel:list --identities')
        ->expectsOutputToContain('3 seen, 1 linked')
        ->expectsOutputToContain('not linked — messages refused')
        ->assertSuccessful();
});
