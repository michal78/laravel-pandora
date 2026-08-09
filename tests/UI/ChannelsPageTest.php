<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Audit\AuditLog;
use Pandora\Channels\ChannelAccount;
use Pandora\Channels\ChannelIdentity;
use Pandora\Tests\Support\MakesChannels;
use Pandora\UI\Livewire\ChannelsIndex;

/**
 * Phase 8, criteria 12, 28, 30 and 31 — the operator surface.
 *
 * The Phase 5 walkthrough is the reason this file is as long as it is. Memory
 * retrieval was proven scoped by twenty-eight criteria, and the Memory *page*
 * still served every user's user-scoped memories to any authenticated account,
 * because every criterion asked what an agent could retrieve and none asked
 * what a page showed a human. So the tenancy assertions here are about the
 * page, not about the model underneath it.
 *
 * The other thing asserted is an absence: this page cannot link an identity to
 * a user. Unlinking only. An operator's belief about who owns a Slack handle is
 * not evidence, and a control that acted on it would make an admin screen an
 * authentication mechanism (ADR-0015).
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.channels.view', static fn (): bool => true);
    Gate::define('pandora.channels.manage', static fn (): bool => true);

    $this->actingAsUser();

    $this->account = $this->makeChannelAccount(['name' => 'Acme Slack']);
});

it('lists the accounts it knows', function (): void {
    Livewire::test(ChannelsIndex::class)
        ->assertOk()
        ->assertSee('Acme Slack')
        ->assertSee('fake-workspace');
});

it('registers an account disabled, whatever the form said', function (): void {
    Livewire::test(ChannelsIndex::class)
        ->call('startCreating')
        ->set('formChannel', 'fake')
        ->set('formName', 'Second workspace')
        ->set('formExternalId', 'W-2')
        ->call('create')
        ->assertHasNoErrors();

    $account = ChannelAccount::query()->where('slug', 'second-workspace')->firstOrFail();

    // Registering where a workspace is and opening a door into this
    // installation are two decisions, and they take two presses.
    expect($account->enabled)->toBeFalse();

    expect(AuditLog::query()->where('action', 'channel.account_created')->count())->toBe(1);
});

it('refuses an account for a channel no adapter provides', function (): void {
    Livewire::test(ChannelsIndex::class)
        ->call('startCreating')
        ->set('formChannel', 'never-installed')
        ->set('formName', 'Ghost')
        ->set('formExternalId', 'W-9')
        ->call('create')
        ->assertSet('error', fn (?string $error): bool => str_contains((string) $error, 'No adapter'));

    expect(ChannelAccount::query()->where('slug', 'ghost')->exists())->toBeFalse();
});

it('shows an account whose adapter is no longer installed', function (): void {
    $orphan = $this->makeChannelAccount([
        'name' => 'Orphaned',
        'channel' => 'removed-extension',
        'external_id' => 'W-orphan',
    ]);

    Livewire::test(ChannelsIndex::class)
        ->assertSee('Orphaned')
        ->assertSee('adapter not installed');

    expect($orphan->exists)->toBeTrue();
});

it('offers no control that links an identity to a user', function (): void {
    $this->makeIdentity($this->account, 'U-1');

    $component = Livewire::test(ChannelsIndex::class)->call('select', $this->account->slug);

    $component->assertSee('not linked')->assertDontSee('Link to user');

    // Structural, so the absence survives a redesign of the markup.
    $methods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(ChannelsIndex::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->toContain('unlink')
        ->and($methods)->not->toContain('link')
        ->and($methods)->not->toContain('linkIdentity');
});

it('unlinks an identity and audits it', function (): void {
    $user = $this->actingAsUser();
    $identity = $this->makeIdentity($this->account, 'U-1', $user);

    Livewire::test(ChannelsIndex::class)
        ->call('select', $this->account->slug)
        ->call('unlink', (string) $identity->getKey());

    expect($identity->fresh()->isLinked())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'channel.identity_unlinked')->count())->toBe(1);
});

it('sends a delivery test through the adapter and reports the real outcome', function (): void {
    $identity = $this->makeIdentity($this->account, 'U-1');

    Livewire::test(ChannelsIndex::class)
        ->call('select', $this->account->slug)
        ->call('sendTest', (string) $identity->getKey())
        ->assertSet('notice', 'Delivered.');

    expect($this->fakeChannel()->lastText())->toBe('Test message from Pandora.')
        ->and(AuditLog::query()->where('action', 'channel.delivery_tested')->count())->toBe(1);

    $this->fakeChannel()->fails('Slack returned 503.');

    Livewire::test(ChannelsIndex::class)
        ->call('select', $this->account->slug)
        ->call('sendTest', (string) $identity->getKey())
        ->assertSet('error', fn (?string $e): bool => str_contains((string) $e, '503'));
});

it('escapes a display name a stranger chose', function (): void {
    $this->makeIdentity($this->account, 'U-1', null, [
        'display_name' => '<script>alert(1)</script>',
    ]);

    Livewire::test(ChannelsIndex::class)
        ->call('select', $this->account->slug)
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('<script>alert(1)</script>');
});

it('refuses an operator holding only pandora.access', function (): void {
    Gate::define('pandora.channels.view', static fn (): bool => false);

    Livewire::test(ChannelsIndex::class)->assertForbidden();
});

it('refuses a write to somebody who may only read', function (): void {
    Gate::define('pandora.channels.manage', static fn (): bool => false);

    Livewire::test(ChannelsIndex::class)->call('startCreating')->assertForbidden();
});

it('is withheld entirely by the feature flag', function (): void {
    config()->set('pandora.features.channels', false);

    // Not a 403 but a 404: the flag decides whether the surface exists at all,
    // and no ability grants past it.
    Livewire::test(ChannelsIndex::class)->assertNotFound();
});

it('does not show another tenant accounts or identities', function (): void {
    $theirs = inTenant('globex', function () {
        $account = $this->makeChannelAccount(['name' => 'Globex Slack', 'external_id' => 'W-globex']);
        $this->makeIdentity($account, 'U-globex', null, ['display_name' => 'Globex Person']);

        return $account;
    });

    inTenant('acme', function () use ($theirs): void {
        Livewire::test(ChannelsIndex::class)
            ->assertDontSee('Globex Slack')
            ->call('select', $theirs->slug)
            ->assertDontSee('Globex Person');
    });
});

it('cannot unlink another tenant identity', function (): void {
    $identity = inTenant('globex', function () {
        $user = $this->actingAsUser();
        $account = $this->makeChannelAccount(['name' => 'Globex Slack', 'external_id' => 'W-globex']);

        return $this->makeIdentity($account, 'U-globex', $user);
    });

    inTenant('acme', function () use ($identity): void {
        Livewire::test(ChannelsIndex::class)->call('unlink', (string) $identity->getKey());
    });

    // The tenant scope makes it not found rather than not permitted, which is
    // the same answer a made-up id gets.
    expect(ChannelIdentity::acrossAllTenants()->findOrFail($identity->getKey())->linked_user_id)
        ->not->toBeNull();
});

it('opens the edit form from the list without inspecting first', function (): void {
    // The form renders beside the SELECTED account, so editing has to select
    // too. It did not, and the Edit button did nothing at all for anyone who
    // had not already clicked Inspect on that row -- which is nobody's first
    // move, and was found by a human clicking it rather than by any test here.
    Livewire::test(ChannelsIndex::class)
        ->call('startEditing', $this->account->slug)
        ->assertSet('selected', $this->account->slug)
        ->assertSee('Edit Acme Slack');
});
