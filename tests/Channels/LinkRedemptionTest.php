<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Channels\LinkCodes;
use Pandora\Tests\Support\MakesChannels;
use Pandora\UI\Livewire\ChannelLink;

/**
 * Phase 8, criterion 4 — redemption links the signed-in user, and cannot be
 * asked to link anybody else.
 *
 * This is the half of the evidence a browser supplies. The code proved control
 * of a channel account; arriving here signed in proves control of a host
 * account. The property that makes it work is negative and is asserted
 * structurally below: no public field on the component names a user, and
 * `LinkCodes::redeem()` has no parameter that could carry one, so a forged
 * submission has nothing to forge.
 *
 * It sits behind `pandora.access` rather than a channels ability on purpose.
 * Linking your own handle to your own account is not administrative, and
 * requiring an operator for every link is what pushes an installation towards
 * the mapping-by-belief shortcut ADR-0015 refuses.
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);

    $this->account = $this->makeChannelAccount();
    $this->identity = $this->makeIdentity($this->account, 'U-1');
    $this->codes = app(LinkCodes::class);
});

it('links the identity to the signed-in user', function (): void {
    $user = $this->actingAsUser();
    $code = $this->codes->issue($this->identity);

    Livewire::test(ChannelLink::class)
        ->set('code', $code)
        ->call('redeem')
        ->assertHasNoErrors()
        ->assertSet('notice', fn (?string $n): bool => str_contains((string) $n, 'Linked'));

    expect($this->identity->fresh()->linked_user_id)->toBe((string) $user->getKey());
});

it('links the person redeeming, not anybody a request might name', function (): void {
    $intended = $this->actingAsUser();
    $attacker = $this->actingAsUser();

    $code = $this->codes->issue($this->identity);

    // The attacker holds the code -- they read it over somebody's shoulder --
    // and redeems it while signed in as themselves. It links THEM, which is
    // correct: holding the code is a claim about the channel account, and the
    // session decides whose host account it joins. There is no parameter that
    // could have said "link it to $intended".
    Livewire::test(ChannelLink::class)
        ->set('code', $code)
        ->call('redeem')
        ->assertHasNoErrors();

    expect($this->identity->fresh()->linked_user_id)
        ->toBe((string) $attacker->getKey())
        ->not->toBe((string) $intended->getKey());
});

it('has no field and no parameter that could name a user', function (): void {
    $properties = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(ChannelLink::class))->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    foreach ($properties as $property) {
        expect($property)->not->toMatch('/user|actor|account_?id|email/i');
    }

    $redeem = (new ReflectionMethod(LinkCodes::class, 'redeem'))->getParameters();

    expect($redeem)->toHaveCount(2)
        ->and($redeem[0]->getName())->toBe('code')
        // An Authorizable, taken from the guard by the only caller that matters
        // -- not a string id a request could supply.
        ->and((string) $redeem[1]->getType())->toContain('Authorizable');
});

it('refuses a bad code without saying which part was wrong', function (): void {
    $this->actingAsUser();

    Livewire::test(ChannelLink::class)
        ->set('code', 'NOTACODE')
        ->call('redeem')
        ->assertSet('error', fn (?string $e): bool => str_contains((string) $e, 'not valid'))
        ->assertSet('code', '');

    expect($this->identity->fresh()->isLinked())->toBeFalse();
});

it('lets somebody unlink their own account and nobody else\'s', function (): void {
    $mine = $this->actingAsUser();
    $this->linkIdentity($this->identity, $mine);

    $theirs = $this->makeIdentity($this->account, 'U-2', $this->actingAsUser());

    // Signed back in as the first user.
    $this->actingAs($mine);

    Livewire::test(ChannelLink::class)
        ->call('unlink', (string) $theirs->getKey())
        ->call('unlink', (string) $this->identity->getKey());

    expect($this->identity->fresh()->isLinked())->toBeFalse()
        ->and($theirs->fresh()->isLinked())->toBeTrue();
});

it('is withheld entirely by the feature flag', function (): void {
    $this->actingAsUser();
    config()->set('pandora.features.channels', false);

    Livewire::test(ChannelLink::class)->assertNotFound();
});
