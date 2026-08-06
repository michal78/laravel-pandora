<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Memory\Embedding;
use Pandora\Pandora\Memory\Embeddings\MemoryEmbedder;
use Pandora\Pandora\Memory\Enums\MemoryScope;
use Pandora\Pandora\Memory\Enums\MemorySource;
use Pandora\Pandora\Memory\Enums\MemoryStatus;
use Pandora\Pandora\Memory\Enums\MemoryType;
use Pandora\Pandora\Memory\MemoryCurator;
use Pandora\Pandora\Memory\MemoryItem;
use Pandora\Pandora\Memory\MemoryQuery;
use Pandora\Pandora\Memory\MemoryRetriever;
use Pandora\Pandora\Memory\MemoryScopeSet;

/**
 * Phase 5, criteria 9 and 10 -- expiry is a predicate, and the sweep is
 * housekeeping.
 *
 * The order matters. If retrieval trusted the sweep, a worker down for a day
 * would mean a day of expired facts still being repeated to people. So the
 * predicate is the guarantee and the sweep only reclaims space -- which is
 * also what makes it safe for the sweep to be a scheduled job rather than
 * something on the read path.
 */
beforeEach(function (): void {
    Gate::define('pandora.memory.manage', static fn (): bool => true);
    $this->actingAsUser();

    $this->curator = app(MemoryCurator::class);
    $this->embedder = app(MemoryEmbedder::class);
    $this->retriever = app(MemoryRetriever::class);
    $this->scopes = MemoryScopeSet::of([
        ['scope' => MemoryScope::Tenant, 'scope_id' => null],
    ]);
});

/**
 * @param array<string, mixed> $attributes
 */
function expiringMemory(string $content, array $attributes = []): MemoryItem
{
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create(array_merge([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'type' => MemoryType::Working->value,
        'content' => $content,
        'source' => MemorySource::Agent->value,
    ], $attributes));

    return $item;
}

it('excludes an expired memory even when the sweep has never run', function (): void {
    expiringMemory('the staging password rotates weekly', [
        'expires_at' => Date::now()->subMinute(),
    ]);

    // No sweep. The predicate is the guarantee.
    expect($this->retriever->retrieve($this->scopes, MemoryQuery::for('staging rotates weekly')))
        ->toBe([]);

    expect(MemoryItem::query()->first()->status)->toBe(MemoryStatus::Active);
});

it('expires due items and records each one', function (): void {
    $due = expiringMemory('due for expiry', ['expires_at' => Date::now()->subHour()]);
    $live = expiringMemory('not due yet', ['expires_at' => Date::now()->addHour()]);
    $forever = expiringMemory('never expires', ['expires_at' => null]);

    expect($this->curator->sweepExpired())->toBe(1);

    expect($due->refresh()->status)->toBe(MemoryStatus::Expired)
        ->and($live->refresh()->status)->toBe(MemoryStatus::Active)
        ->and($forever->refresh()->status)->toBe(MemoryStatus::Active);

    $audit = AuditLog::query()->where('action', 'memory.expired')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($due->getKey());
});

it('deletes the vector of an expired memory', function (): void {
    $item = expiringMemory('due for expiry', ['expires_at' => Date::now()->subHour()]);
    $this->embedder->embed($item);

    expect(Embedding::query()->count())->toBe(1);

    $this->curator->sweepExpired();

    // An expired memory with a live vector is still findable by the path that
    // matters, so the vector goes even though the row stays.
    expect(Embedding::query()->count())->toBe(0)
        ->and($item->refresh()->embedding_id)->toBeNull();
});

it('expires a suggestion nobody got round to reviewing', function (): void {
    $item = expiringMemory('a suggestion', [
        'status' => MemoryStatus::Suggested->value,
        'expires_at' => Date::now()->subHour(),
    ]);

    expect($this->curator->sweepExpired())->toBe(1)
        ->and($item->refresh()->status)->toBe(MemoryStatus::Expired);
});

it('leaves an already-expired or rejected item alone', function (): void {
    expiringMemory('already expired', [
        'status' => MemoryStatus::Expired->value,
        'expires_at' => Date::now()->subDay(),
    ]);
    expiringMemory('rejected', [
        'status' => MemoryStatus::Rejected->value,
        'expires_at' => Date::now()->subDay(),
    ]);

    // Re-expiring these would write an audit entry every sweep, forever.
    expect($this->curator->sweepExpired())->toBe(0)
        ->and(AuditLog::query()->where('action', 'memory.expired')->count())->toBe(0);
});

it('bounds how much one sweep does', function (): void {
    foreach (range(1, 5) as $n) {
        expiringMemory("item {$n}", ['expires_at' => Date::now()->subHour()]);
    }

    expect($this->curator->sweepExpired(limit: 2))->toBe(2)
        ->and($this->curator->sweepExpired(limit: 2))->toBe(2)
        ->and($this->curator->sweepExpired(limit: 2))->toBe(1)
        ->and($this->curator->sweepExpired(limit: 2))->toBe(0);
});

it('sweeps across every tenant, since nobody is logged in to a schedule', function (): void {
    inTenant('acme', fn () => expiringMemory('acme item', ['expires_at' => Date::now()->subHour()]));
    inTenant('globex', fn () => expiringMemory('globex item', ['expires_at' => Date::now()->subHour()]));

    // A sweep that only saw the current tenant would leave every other
    // tenant's expired memory in place forever.
    expect($this->curator->sweepExpired())->toBe(2);
});

it('is exposed as a command', function (): void {
    expiringMemory('due for expiry', ['expires_at' => Date::now()->subHour()]);

    $this->artisan('pandora:memory:sweep')->assertSuccessful();

    expect(MemoryItem::query()->first()->status)->toBe(MemoryStatus::Expired);
});
