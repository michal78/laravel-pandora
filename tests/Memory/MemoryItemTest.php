<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Pandora\Exceptions\InvalidMemoryScope;
use Pandora\Memory\Embedding;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySensitivity;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryStatus;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryItem;

/**
 * Phase 5, criterion 1 -- the record itself.
 *
 * Nothing here retrieves anything; retrieval is criterion 2 onwards and lives
 * behind `MemoryRetriever`. What this file pins down is that a row cannot
 * exist in a shape that a later retrieval would have to be clever about: a
 * scope that identifies nobody, an expired item that still claims to be
 * retrievable, a forgotten item that kept its vector.
 */
/**
 * @param array<string, mixed> $attributes
 */
function memoryItem(array $attributes = []): MemoryItem
{
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create(array_merge([
        'scope' => MemoryScope::User->value,
        'scope_id' => 'user:1',
        'type' => MemoryType::UserFact->value,
        'content' => 'Prefers the window seat.',
        'source' => MemorySource::User->value,
    ], $attributes));

    return $item;
}

it('persists every scope and type', function (): void {
    foreach (MemoryScope::cases() as $scope) {
        $item = memoryItem([
            'scope' => $scope->value,
            'scope_id' => $scope->requiresScopeId() ? 'owner:1' : null,
        ]);

        expect($item->refresh()->scope)->toBe($scope);
    }

    foreach (MemoryType::cases() as $type) {
        $item = memoryItem(['type' => $type->value]);

        expect($item->refresh()->type)->toBe($type);
    }
});

it('casts source, sensitivity and status, and defaults sensibly', function (): void {
    $item = memoryItem()->refresh();

    expect($item->source)->toBe(MemorySource::User)
        ->and($item->sensitivity)->toBe(MemorySensitivity::Normal)
        ->and($item->status)->toBe(MemoryStatus::Active)
        ->and($item->confidence)->toBe(100)
        ->and($item->retrieval_count)->toBe(0);
});

it('refuses a scope that identifies nobody', function (): void {
    expect(fn () => memoryItem(['scope' => MemoryScope::User->value, 'scope_id' => null]))
        ->toThrow(InvalidMemoryScope::class);

    expect(fn () => memoryItem(['scope' => MemoryScope::Agent->value, 'scope_id' => '']))
        ->toThrow(InvalidMemoryScope::class);
});

it('refuses a scope id on a scope that takes none', function (): void {
    expect(fn () => memoryItem(['scope' => MemoryScope::Tenant->value, 'scope_id' => 'user:1']))
        ->toThrow(InvalidMemoryScope::class);
});

it('keeps provenance, structure and metadata as arrays', function (): void {
    $item = memoryItem([
        'structured' => ['seat' => 'window'],
        'provenance' => ['stated_in' => 'conversation', 'verbatim' => true],
        'metadata' => ['imported' => false],
        'source' => MemorySource::Agent->value,
        'confidence' => 60,
    ])->refresh();

    expect($item->structured)->toBe(['seat' => 'window'])
        ->and($item->provenance['verbatim'])->toBeTrue()
        ->and($item->metadata)->toBe(['imported' => false])
        ->and($item->confidence)->toBe(60);
});

it('excludes suggested, rejected and expired items from the retrievable set', function (): void {
    $active = memoryItem(['content' => 'active']);
    memoryItem(['content' => 'suggested', 'status' => MemoryStatus::Suggested->value]);
    memoryItem(['content' => 'rejected', 'status' => MemoryStatus::Rejected->value]);
    memoryItem(['content' => 'expired-status', 'status' => MemoryStatus::Expired->value]);

    $ids = MemoryItem::query()->retrievable()->pluck('id')->all();

    expect($ids)->toBe([$active->getKey()]);
});

it('excludes an item past its expiry even when the sweep has not run', function (): void {
    $live = memoryItem(['content' => 'live', 'expires_at' => Date::now()->addHour()]);
    memoryItem(['content' => 'stale', 'expires_at' => Date::now()->subSecond()]);
    $forever = memoryItem(['content' => 'forever', 'expires_at' => null]);

    $ids = MemoryItem::query()->retrievable()->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$live->getKey(), $forever->getKey()]);
});

it('excludes a soft-deleted item', function (): void {
    $item = memoryItem();
    $item->delete();

    expect(MemoryItem::query()->retrievable()->count())->toBe(0)
        ->and(MemoryItem::withTrashed()->count())->toBe(1);
});

it('reports expiry and retrievability on the instance', function (): void {
    expect(memoryItem(['expires_at' => Date::now()->subMinute()])->hasExpired())->toBeTrue()
        ->and(memoryItem(['expires_at' => Date::now()->subMinute()])->isRetrievable())->toBeFalse()
        ->and(memoryItem(['status' => MemoryStatus::Suggested->value])->isRetrievable())->toBeFalse()
        ->and(memoryItem()->isRetrievable())->toBeTrue();
});

it('builds embeddable text from the title and the content', function (): void {
    $withTitle = memoryItem(['title' => 'Seating', 'content' => 'Prefers the window seat.']);
    $without = memoryItem(['title' => null, 'content' => 'Prefers the window seat.']);

    expect($withTitle->embeddableText())->toBe("Seating\nPrefers the window seat.")
        ->and($without->embeddableText())->toBe('Prefers the window seat.');
});

it('stores one embedding per owner, provider and model', function (): void {
    $item = memoryItem();

    $embedding = Embedding::query()->create([
        'owner_type' => MemoryItem::class,
        'owner_id' => $item->getKey(),
        'provider_key' => 'openai',
        'model_key' => 'text-embedding-3-small',
        'dimensions' => 3,
        'vector' => [0.1, 0.2, 0.3],
        'content_hash' => Embedding::hash($item->embeddableText()),
    ]);

    expect($embedding->refresh()->vector)->toBe([0.1, 0.2, 0.3])
        ->and($embedding->dimensions)->toBe(3);

    $item->update(['embedding_id' => $embedding->getKey()]);

    expect($item->refresh()->embedding->getKey())->toBe($embedding->getKey());
});

it('hashes embeddable content stably, ignoring surrounding whitespace', function (): void {
    expect(Embedding::hash(' a fact '))->toBe(Embedding::hash('a fact'))
        ->and(Embedding::hash('a fact'))->not->toBe(Embedding::hash('another fact'));
});

it('scopes items awaiting review separately from retrievable ones', function (): void {
    memoryItem(['content' => 'active']);
    $suggested = memoryItem([
        'content' => 'sensitive claim',
        'status' => MemoryStatus::Suggested->value,
        'sensitivity' => MemorySensitivity::Sensitive->value,
    ]);

    expect(MemoryItem::query()->awaitingReview()->pluck('id')->all())
        ->toBe([$suggested->getKey()]);
});
