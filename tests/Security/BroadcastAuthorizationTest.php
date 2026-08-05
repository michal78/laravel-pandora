<?php

declare(strict_types=1);

use Pandora\Pandora\Core\Tenancy\TenantContext;
use Pandora\Pandora\Core\Tenancy\TenantManager;
use Pandora\Pandora\Realtime\ChannelAuthorizer;
use Pandora\Pandora\Tests\Fixtures\TestUser;
use Pandora\Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/**
 * Acceptance guarantee 17 -- broadcast authorization.
 *
 * The id in a channel name is an INPUT, never a claim. These tests exist
 * because that refusal is the only thing standing between tenants sharing one
 * Reverb server.
 */
beforeEach(function (): void {
    $this->alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.test', 'password' => 'x']);
    $this->mallory = TestUser::create(['name' => 'Mallory', 'email' => 'm@example.test', 'password' => 'x']);
    $this->authorizer = app(ChannelAuthorizer::class);
});

it('lets a conversation owner subscribe to it', function (): void {
    $this->actingAs($this->alice);

    $conversation = $this->makeConversation(null, [
        'created_by_type' => $this->alice::class,
        'created_by_id' => (string) $this->alice->getKey(),
    ]);

    expect($this->authorizer->canAccessConversation($this->alice, (string) $conversation->getKey()))
        ->toBeTrue();
});

it('refuses another user\'s conversation', function (): void {
    $this->actingAs($this->mallory);

    $conversation = $this->makeConversation(null, [
        'created_by_type' => $this->alice::class,
        'created_by_id' => (string) $this->alice->getKey(),
    ]);

    expect($this->authorizer->canAccessConversation($this->mallory, (string) $conversation->getKey()))
        ->toBeFalse();
});

it('refuses a conversation belonging to another tenant', function (): void {
    $tenants = app(TenantManager::class);

    $conversation = $tenants->with(new TenantContext('acme'), function () {
        return $this->makeConversation(null, [
            'created_by_type' => $this->alice::class,
            'created_by_id' => (string) $this->alice->getKey(),
        ]);
    });

    $this->actingAs($this->alice);

    // Same user, right id, wrong tenant.
    $allowed = $tenants->with(new TenantContext('globex'),
        fn (): bool => $this->authorizer->canAccessConversation($this->alice, (string) $conversation->getKey()));

    expect($allowed)->toBeFalse();
});

it('refuses an unauthenticated subscriber on every channel', function (): void {
    expect($this->authorizer->canAccessConversation(null, 'anything'))->toBeFalse()
        ->and($this->authorizer->canAccessRun(null, 'anything'))->toBeFalse()
        ->and($this->authorizer->isSameUser(null, '1'))->toBeFalse()
        ->and($this->authorizer->canAccessTenant(null, 'acme'))->toBeFalse()
        ->and($this->authorizer->canAccessSystem(null))->toBeFalse();
});

it('refuses a user channel belonging to somebody else', function (): void {
    expect($this->authorizer->isSameUser($this->alice, (string) $this->mallory->getKey()))->toBeFalse()
        ->and($this->authorizer->isSameUser($this->alice, (string) $this->alice->getKey()))->toBeTrue();
});

it('refuses the system channel without settings.manage', function (): void {
    $this->actingAs($this->alice);

    // The default gate denies every administrative ability.
    expect($this->authorizer->canAccessSystem($this->alice))->toBeFalse();
});

it('refuses another tenant\'s tenant channel', function (): void {
    $this->actingAs($this->alice);

    $allowed = app(TenantManager::class)->with(new TenantContext('acme'),
        fn (): bool => $this->authorizer->canAccessTenant($this->alice, 'globex'));

    expect($allowed)->toBeFalse();
});

it('lets a run owner subscribe to their own run', function (): void {
    $this->actingAs($this->alice);

    $run = $this->makeRun([
        'actor_type' => $this->alice::class,
        'actor_id' => (string) $this->alice->getKey(),
    ]);

    expect($this->authorizer->canAccessRun($this->alice, (string) $run->getKey()))->toBeTrue()
        ->and($this->authorizer->canAccessRun($this->mallory, (string) $run->getKey()))->toBeFalse();
});
