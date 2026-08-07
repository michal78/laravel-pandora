<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Pandora\Audit\AuditLogger;
use Pandora\Memory\Embeddings\HashEmbeddingProvider;
use Pandora\Memory\Embeddings\MemoryEmbedder;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryItem;
use Pandora\Memory\MemoryQuery;
use Pandora\Memory\MemoryRetriever;
use Pandora\Memory\MemoryScopeSet;
use Pandora\Memory\Vector\PgvectorStore;

/**
 * Phase 5, criterion 17 -- the pgvector adapter, exercised for real.
 *
 * Phase 4 produced seven defects and not one was reachable by the suite as
 * configured. "Optional, therefore untested" is that shape exactly, and a
 * vector store is an optional dependency added on purpose. So this file does
 * not mock pgvector: on PostgreSQL it runs the extension, and everywhere else
 * it SKIPS rather than passing.
 *
 * A skipped test is honest about not having run. A test that quietly passes
 * because it substituted a fake for the thing under test is the failure mode
 * the whole phase was written to avoid.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('pgvector requires PostgreSQL; the CI matrix runs this leg.');
    }

    $this->embeddings = new HashEmbeddingProvider(
        (int) config('pandora.memory.embeddings.dimensions', 256),
    );

    $this->store = new PgvectorStore(DB::connection(), 'pandora_embeddings');

    if (! $this->store->isAvailable()) {
        test()->markTestSkipped('The vector extension is not installed on this PostgreSQL server.');
    }

    $this->embedder = new MemoryEmbedder($this->embeddings, $this->store);
    $this->scopes = MemoryScopeSet::of([
        ['scope' => MemoryScope::Tenant, 'scope_id' => null],
    ]);
});

/**
 * @param array<string, mixed> $attributes
 */
function pgMemory(string $content, array $attributes = []): MemoryItem
{
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create(array_merge([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'type' => MemoryType::UserFact->value,
        'content' => $content,
        'source' => MemorySource::User->value,
    ], $attributes));

    return $item;
}

it('reports itself available when the extension and column exist', function (): void {
    expect($this->store->isAvailable())->toBeTrue()
        ->and($this->store->key())->toBe('pgvector');
});

it('returns nearest neighbours, closest first', function (): void {
    $near = pgMemory('rollback the deploy immediately');
    $far = pgMemory('the office cat prefers tuna');

    $this->embedder->embed($near);
    $this->embedder->embed($far);

    $matches = $this->store->search(
        MemoryItem::class,
        $this->embeddings->embed('rollback the deploy immediately'),
        5,
    );

    expect($matches)->toHaveCount(2)
        ->and($matches[0]->ownerId)->toBe($near->getKey())
        ->and($matches[0]->distance)->toBeLessThan($matches[1]->distance)
        ->and($matches[0]->distance)->toBeLessThan(0.0001);
});

it('writes the native column alongside the portable one', function (): void {
    $item = pgMemory('rollback the deploy immediately');
    $this->embedder->embed($item);

    $row = DB::selectOne(
        'select vector, vector_native from pandora_embeddings where owner_id = ?',
        [$item->getKey()],
    );

    // Both copies. The JSON one is what makes swapping adapters a
    // configuration change rather than a re-embedding project.
    expect($row->vector)->not->toBeNull()
        ->and($row->vector_native)->not->toBeNull();
});

it('re-filters its results against the scope constraint', function (): void {
    // The safety property, exercised against the real index: pgvector will
    // happily return another user's memory, and the database refuses it.
    $foreign = MemoryItem::query()->create([
        'scope' => MemoryScope::User->value,
        'scope_id' => 'App\\Models\\User#999',
        'type' => MemoryType::UserFact->value,
        'content' => 'the recovery phrase is orchid',
        'source' => MemorySource::User->value,
    ]);

    $this->embedder->embed($foreign);

    $direct = $this->store->search(
        MemoryItem::class,
        $this->embeddings->embed('the recovery phrase is orchid'),
        5,
    );

    // The store proposes it...
    expect($direct)->toHaveCount(1)
        ->and($direct[0]->ownerId)->toBe($foreign->getKey());

    $retriever = new MemoryRetriever(
        $this->embeddings,
        $this->store,
        app(AuditLogger::class),
    );

    // ...and retrieval refuses it anyway.
    expect($retriever->retrieve($this->scopes, MemoryQuery::for('recovery phrase orchid')))
        ->toBe([]);
});

it('surfaces a memory through the retriever when scope permits', function (): void {
    $item = pgMemory('rollback procedures live in the runbook');
    $this->embedder->embed($item);

    $retriever = new MemoryRetriever(
        $this->embeddings,
        $this->store,
        app(AuditLogger::class),
    );

    $results = $retriever->retrieve($this->scopes, MemoryQuery::for('rollback runbook'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->item->getKey())->toBe($item->getKey())
        ->and($results[0]->strategy)->toBe(MemoryRetriever::STRATEGY_HYBRID);
});

it('clears the native vector when a memory is forgotten', function (): void {
    $item = pgMemory('rollback the deploy immediately');
    $this->embedder->embed($item);

    $this->store->forget(MemoryItem::class, (string) $item->getKey());

    $matches = $this->store->search(
        MemoryItem::class,
        $this->embeddings->embed('rollback the deploy immediately'),
        5,
    );

    expect($matches)->toBe([]);
});

it('backfills native vectors from the portable column', function (): void {
    $item = pgMemory('rollback the deploy immediately');
    $this->embedder->embed($item);

    // Simulate an installation that embedded before enabling the extension.
    DB::update('update pandora_embeddings set vector_native = null');

    expect($this->store->search(MemoryItem::class, $this->embeddings->embed('rollback deploy'), 5))
        ->toBe([]);

    // Turning pgvector on must not mean re-paying for a corpus already stored.
    expect($this->store->backfill())->toBe(1)
        ->and($this->store->search(MemoryItem::class, $this->embeddings->embed('rollback the deploy immediately'), 5))
        ->toHaveCount(1);
});

it('ignores a vector of the wrong width rather than comparing it', function (): void {
    $item = pgMemory('a memory embedded with an older model');
    $this->embedder->embed($item);

    DB::update('update pandora_embeddings set dimensions = 8 where owner_id = ?', [$item->getKey()]);

    expect($this->store->search(MemoryItem::class, $this->embeddings->embed('a memory'), 5))
        ->toBe([]);
});
