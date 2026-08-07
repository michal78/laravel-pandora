<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Pandora\Audit\AuditLog;
use Pandora\Memory\Embedding;
use Pandora\Memory\Embeddings\MemoryEmbedder;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryCurator;
use Pandora\Memory\MemoryItem;
use Pandora\Memory\MemoryQuery;
use Pandora\Memory\MemoryRetriever;
use Pandora\Memory\MemoryScopeSet;

/**
 * Phase 5, criterion 14 -- "forget that" removes the thing that makes it
 * retrievable.
 *
 * The asymmetry is the feature: the row is soft-deleted, the vector is
 * hard-deleted. A soft-deleted row whose vector is still indexed is still
 * findable by the path that matters, which would make "forgotten" a label
 * rather than a fact. The row survives so an audit can still show what was
 * forgotten and when.
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

function forgettableMemory(string $content = 'the deploy window is thursday'): MemoryItem
{
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'type' => MemoryType::AgentCurated->value,
        'content' => $content,
        'source' => MemorySource::Agent->value,
    ]);

    return $item;
}

it('stops a forgotten memory being retrieved', function (): void {
    $item = forgettableMemory();
    $this->embedder->embed($item);

    expect($this->retriever->retrieve($this->scopes, MemoryQuery::for('deploy window thursday')))
        ->toHaveCount(1);

    $this->curator->forget($item, 'they asked us to');

    expect($this->retriever->retrieve($this->scopes, MemoryQuery::for('deploy window thursday')))
        ->toBe([]);
});

it('hard-deletes the vector while keeping the row for the audit', function (): void {
    $item = forgettableMemory();
    $this->embedder->embed($item);

    expect(Embedding::query()->count())->toBe(1);

    $this->curator->forget($item);

    expect(Embedding::query()->count())->toBe(0)
        ->and(MemoryItem::query()->count())->toBe(0)
        ->and(MemoryItem::withTrashed()->count())->toBe(1);
});

it('records what was forgotten and why', function (): void {
    $item = forgettableMemory();

    $this->curator->forget($item, 'subject access request');

    $audit = AuditLog::query()->where('action', 'memory.forgotten')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($item->getKey())
        ->and($audit->metadata['reason'])->toBe('subject access request')
        ->and($audit->metadata['scope'])->toBe('tenant');
});

it('requires the manage ability', function (): void {
    Gate::define('pandora.memory.manage', static fn (): bool => false);

    expect(fn () => $this->curator->forget(forgettableMemory()))
        ->toThrow(AuthorizationException::class);
});

it('is exposed as a command', function (): void {
    $item = forgettableMemory();
    $this->embedder->embed($item);

    // "Delete what you know about me" arrives as an email on a Sunday.
    $this->artisan('pandora:memory:forget', ['id' => $item->getKey(), '--reason' => 'sar'])
        ->assertSuccessful();

    expect(MemoryItem::query()->count())->toBe(0)
        ->and(Embedding::query()->count())->toBe(0);
});

it('fails cleanly when the command names a memory that does not exist', function (): void {
    $this->artisan('pandora:memory:forget', ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])
        ->assertFailed();
});
