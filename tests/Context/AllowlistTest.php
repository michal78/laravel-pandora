<?php

declare(strict_types=1);

use Pandora\Pandora\Context\AttributeAllowlist;
use Pandora\Pandora\Memory\Enums\MemoryScope;
use Pandora\Pandora\Memory\Enums\MemorySource;
use Pandora\Pandora\Memory\Enums\MemoryType;
use Pandora\Pandora\Memory\MemoryItem;
use Pandora\Pandora\Tests\Fixtures\TestUser;

/**
 * Phase 5, criterion 22 -- a provider exposes only what it named.
 *
 * The failure this prevents is dull and total. `$user->toArray()` in a context
 * provider puts the password hash, the remember token and whatever column the
 * host added last week into a prompt, which is then sent to a third party and
 * kept in their logs. Nothing throws. Nothing looks wrong.
 */
it('exposes only the attributes it was given', function (): void {
    $user = TestUser::query()->create([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => bcrypt('correct horse battery staple'),
    ]);

    $projected = AttributeAllowlist::of(['name'])->project($user);

    expect($projected)->toBe(['name' => 'Ada']);
});

it('omits a secret even when the model is happy to hand it over', function (): void {
    $user = TestUser::query()->create([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => bcrypt('correct horse battery staple'),
    ]);

    $projected = AttributeAllowlist::of(['name', 'email'])->project($user);
    $serialised = json_encode($projected, JSON_THROW_ON_ERROR);

    expect(array_keys($projected))->toBe(['name', 'email'])
        ->and($serialised)->not->toContain('$2y$')
        ->and($projected)->not->toHaveKey('password')
        ->and($projected)->not->toHaveKey('remember_token');
});

it('drops an attribute that does not exist rather than fataling', function (): void {
    $user = TestUser::query()->create([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => bcrypt('secret'),
    ]);

    expect(AttributeAllowlist::of(['name', 'nonexistent_column'])->project($user))
        ->toBe(['name' => 'Ada']);
});

it('refuses to serialise a nested structure reached through an allowlisted name', function (): void {
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'type' => MemoryType::UserFact->value,
        'content' => 'a fact',
        'source' => MemorySource::User->value,
        'structured' => ['secret' => 'hunter2'],
    ]);

    // `structured` is an array cast. Allowlisting its name must not smuggle
    // its contents into the prompt -- nobody allowlisted what is inside it.
    $projected = AttributeAllowlist::of(['content', 'structured'])->project($item);

    expect($projected['content'])->toBe('a fact')
        ->and($projected['structured'])->toBe('[not exposed]')
        ->and(json_encode($projected, JSON_THROW_ON_ERROR))->not->toContain('hunter2');
});

it('renders enums, booleans and stringables predictably', function (): void {
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'type' => MemoryType::Summary->value,
        'content' => 'a summary',
        'source' => MemorySource::Summariser->value,
        'confidence' => 80,
    ]);

    $projected = AttributeAllowlist::of(['type', 'confidence', 'created_at'])->project($item);

    expect($projected['type'])->toBe('summary')
        ->and($projected['confidence'])->toBe('80')
        ->and($projected['created_at'])->toBeString();
});

it('deduplicates the names it was given', function (): void {
    expect(AttributeAllowlist::of(['name', 'name', 'email'])->attributes)
        ->toBe(['name', 'email']);
});

it('projects a list of models', function (): void {
    TestUser::query()->create(['name' => 'Ada', 'email' => 'a@example.com', 'password' => bcrypt('x')]);
    TestUser::query()->create(['name' => 'Grace', 'email' => 'g@example.com', 'password' => bcrypt('x')]);

    $projected = AttributeAllowlist::of(['name'])->projectAll(TestUser::query()->get()->all());

    expect($projected)->toBe([['name' => 'Ada'], ['name' => 'Grace']]);
});
