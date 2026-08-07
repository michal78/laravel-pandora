<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pandora\Tests\Fixtures\AutomationFactory;

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
        ->assertSee('Automations')
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
        // An agent row decides which tools a language model can reach, so
        // editing one is administrative however ordinary the page looks.
        ->and(Gate::forUser($user)->allows('pandora.agents.manage'))->toBeFalse()
        // Phase 4 criterion: an automation acts unattended, so being able to
        // create one is administrative by definition.
        ->and(Gate::forUser($user)->allows('pandora.automations.manage'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('pandora.approvals.resolve'))->toBeFalse();
});

it('grants a guest nothing at all', function (): void {
    expect(Gate::allows('pandora.access'))->toBeFalse()
        ->and(Gate::allows('pandora.usage.view'))->toBeFalse();
});

it('reaches both automation pages over HTTP from the sidebar', function (): void {
    // The Livewire tests exercise the components; this proves the routes are
    // registered, the layout renders them, and the link in the sidebar goes
    // somewhere real.
    Gate::define('pandora.automations.manage', static fn (): bool => true);

    $this->actingAsUser();

    $automation = AutomationFactory::make();

    $this->get(route('pandora.automations'))->assertOk()->assertSee('Nightly report');
    $this->get(route('pandora.automations.show', ['automation' => $automation->slug]))
        ->assertOk()
        ->assertSee('Nightly report');
});

/**
 * A deferred feature is shown and inert.
 *
 * Hiding it entirely would leave an operator who has read the guides unable to
 * tell whether the page is missing or broken; linking it would offer a page
 * that does not do what the label says.
 */
it('marks a deferred feature as coming soon rather than linking it', function (): void {
    config()->set('pandora.features.workspaces', false);

    Gate::define('pandora.workspaces.access', static fn (): bool => true);

    $this->actingAsUser();

    $response = $this->get(route('pandora.dashboard'))->assertOk()->assertSee('Coming soon');

    // Named, and not offered as somewhere to go.
    expect($response->getContent())
        ->toContain('Workspaces')
        ->not->toContain('href="'.route('pandora.workspaces').'"');
});

it('links it normally once the feature is enabled', function (): void {
    config()->set('pandora.features.workspaces', true);

    Gate::define('pandora.workspaces.access', static fn (): bool => true);

    $this->actingAsUser();

    $response = $this->get(route('pandora.dashboard'))->assertOk();

    expect($response->getContent())
        ->toContain('href="'.route('pandora.workspaces').'"')
        ->not->toContain('Coming soon');
});
