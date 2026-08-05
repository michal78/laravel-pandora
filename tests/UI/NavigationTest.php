<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;

/**
 * The sidebar offers no link that answers 403.
 *
 * Not a security boundary — every page authorizes on mount, and
 * `ProvidersPageTest` and `UsagePageTest` prove it. This is about not teaching
 * people to ignore authorization errors: a control center whose own navigation
 * is half forbidden trains its users to click through failures.
 */
it('shows the pages an ordinary authenticated user may open', function (): void {
    $this->actingAsUser();

    $this->get(route('pandora.dashboard'))
        ->assertOk()
        ->assertSee('Providers')
        ->assertSee('Runs')
        ->assertSee('Tools');
});

it('hides a page the user may not open', function (): void {
    Gate::define('pandora.usage.view', static fn (): bool => false);

    $this->actingAsUser();

    $this->get(route('pandora.dashboard'))
        ->assertOk()
        ->assertDontSee('>Usage<', false);
});

it('shows it again once the ability is granted', function (): void {
    Gate::define('pandora.usage.view', static fn (): bool => true);

    $this->actingAsUser();

    $this->get(route('pandora.dashboard'))->assertOk()->assertSee('Usage');
});

it('grants an authenticated user the read-only abilities by default', function (): void {
    // A fresh installation is usable without writing a single gate. Knowing
    // how many tokens were spent cannot cause harm; knowing what they COST
    // can, so `costs.view` is not on this list.
    $user = $this->actingAsUser();

    expect(Gate::forUser($user)->allows('pandora.access'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('pandora.chat'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('pandora.usage.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('pandora.costs.view'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('pandora.providers.manage'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('pandora.approvals.resolve'))->toBeFalse();
});

it('grants a guest nothing at all', function (): void {
    expect(Gate::allows('pandora.access'))->toBeFalse()
        ->and(Gate::allows('pandora.usage.view'))->toBeFalse();
});
