<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Audit\AuditLogger;
use Pandora\Contracts\VectorStore;
use Pandora\Memory\Embeddings\HashEmbeddingProvider;
use Pandora\Memory\Embeddings\MemoryEmbedder;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryItem;
use Pandora\Memory\MemoryQuery;
use Pandora\Memory\MemoryRetriever;
use Pandora\Memory\MemoryScopeSet;
use Pandora\Memory\Vector\DatabaseVectorStore;
use Pandora\Memory\Vector\VectorMatch;

/**
 * Phase 5, criteria 16 and 19 -- the contracts, and what happens when the
 * store is not there.
 *
 * A vector store is an accelerator and never an authority. The tests that
 * matter here are not "does it find similar things" but "can it be wrong
 * without hurting anyone": an index Pandora does not control, cannot audit,
 * and which may be serving rows from before a memory was forgotten must not be
 * able to surface anything the database says this runner may not see.
 */
beforeEach(function (): void {
    $this->embeddings = new HashEmbeddingProvider(64);
    $this->store = new DatabaseVectorStore;
    $this->embedder = new MemoryEmbedder($this->embeddings, $this->store);
    $this->scopes = MemoryScopeSet::of([
        ['scope' => MemoryScope::Tenant, 'scope_id' => null],
    ]);
});

/**
 * @param array<string, mixed> $attributes
 */
function vectorMemory(string $content, array $attributes = []): MemoryItem
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

it('round-trips a vector through the portable database store', function (): void {
    $item = vectorMemory('The deploy window is Thursday afternoon.');

    expect($this->embedder->embed($item))->toBeTrue();

    $item->refresh();

    expect($item->embedding_id)->not->toBeNull()
        ->and($item->embedding->dimensions)->toBe(64)
        ->and($item->embedding->vector)->toHaveCount(64)
        ->and($item->embedding->provider_key)->toBe('hash');

    $matches = $this->store->search(
        MemoryItem::class,
        $this->embeddings->embed('The deploy window is Thursday afternoon.'),
        5,
    );

    expect($matches)->toHaveCount(1)
        ->and($matches[0])->toBeInstanceOf(VectorMatch::class)
        ->and($matches[0]->ownerId)->toBe($item->getKey())
        ->and($matches[0]->distance)->toBeLessThan(0.0001);
});

it('ranks a closer vector above a more distant one', function (): void {
    $near = vectorMemory('rollback the deploy immediately');
    $far = vectorMemory('the office cat prefers tuna');

    $this->embedder->embed($near);
    $this->embedder->embed($far);

    $matches = $this->store->search(
        MemoryItem::class,
        $this->embeddings->embed('rollback the deploy immediately'),
        5,
    );

    expect($matches[0]->ownerId)->toBe($near->getKey())
        ->and($matches[0]->distance)->toBeLessThan($matches[1]->distance);
});

it('ignores a stored vector of a different width', function (): void {
    // What a changed embedding model looks like from here. Zero-padding to
    // compare anyway would produce confident nonsense.
    $item = vectorMemory('a memory embedded with an older model');
    (new MemoryEmbedder(new HashEmbeddingProvider(32), $this->store))->embed($item);

    $matches = $this->store->search(MemoryItem::class, $this->embeddings->embed('a memory'), 5);

    expect($matches)->toBe([]);
});

it('degrades to lexical retrieval when the store throws, and records it', function (): void {
    $item = vectorMemory('rollback the deploy immediately');
    $this->embedder->embed($item);

    $broken = new class implements VectorStore
    {
        public function key(): string
        {
            return 'broken';
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function upsert(string $ownerType, string $ownerId, array $vector): void {}

        public function forget(string $ownerType, string $ownerId): void {}

        public function search(string $ownerType, array $vector, int $limit): array
        {
            throw new RuntimeException('vector host unreachable');
        }
    };

    $retriever = new MemoryRetriever($this->embeddings, $broken, app(AuditLogger::class));

    $results = $retriever->retrieve($this->scopes, MemoryQuery::for('rollback deploy'));

    // Worse recall, never a failed answer.
    expect($results)->toHaveCount(1)
        ->and($results[0]->item->getKey())->toBe($item->getKey())
        ->and($results[0]->strategy)->toBe(MemoryRetriever::STRATEGY_LEXICAL);

    $audit = AuditLog::query()->where('action', 'memory.retrieval_degraded')->first();

    // "Memory got quietly worse three weeks ago" is otherwise indistinguishable
    // from "the agent is not as good as it was".
    expect($audit)->not->toBeNull()
        ->and($audit->severity)->toBe('warning')
        ->and($audit->metadata['store'])->toBe('broken');
});

it('skips a store that reports itself unavailable without recording a failure', function (): void {
    $item = vectorMemory('rollback the deploy immediately');
    $this->embedder->embed($item);

    $absent = new class implements VectorStore
    {
        public function key(): string
        {
            return 'absent';
        }

        public function isAvailable(): bool
        {
            return false;
        }

        public function upsert(string $ownerType, string $ownerId, array $vector): void {}

        public function forget(string $ownerType, string $ownerId): void {}

        public function search(string $ownerType, array $vector, int $limit): array
        {
            throw new RuntimeException('should never be called');
        }
    };

    $retriever = new MemoryRetriever($this->embeddings, $absent, app(AuditLogger::class));

    expect($retriever->retrieve($this->scopes, MemoryQuery::for('rollback deploy')))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'memory.retrieval_degraded')->count())->toBe(0);
});

it('re-filters everything the store proposes against the scope constraint', function (): void {
    // The safety property. The store is told to propose a memory belonging to
    // somebody else; the database refuses it anyway.
    $foreign = MemoryItem::query()->create([
        'scope' => MemoryScope::User->value,
        'scope_id' => 'App\\Models\\User#999',
        'type' => MemoryType::UserFact->value,
        'content' => 'the recovery phrase is orchid',
        'source' => MemorySource::User->value,
    ]);

    $this->embedder->embed($foreign);

    $overreaching = new class($foreign->getKey()) implements VectorStore
    {
        public function __construct(private readonly string $id) {}

        public function key(): string
        {
            return 'overreaching';
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function upsert(string $ownerType, string $ownerId, array $vector): void {}

        public function forget(string $ownerType, string $ownerId): void {}

        public function search(string $ownerType, array $vector, int $limit): array
        {
            // A stale or malicious index proposing a memory it should not.
            return [new VectorMatch($this->id, 0.0)];
        }
    };

    $retriever = new MemoryRetriever($this->embeddings, $overreaching, app(AuditLogger::class));

    expect($retriever->retrieve($this->scopes, MemoryQuery::for('orchid recovery phrase')))
        ->toBe([]);
});

it('lets the store surface a memory lexical search would have missed', function (): void {
    // The reason the vector path exists: no shared tokens, so the LIKE finds
    // nothing, and the store proposes it anyway.
    $item = vectorMemory('rollback procedures live in the runbook');
    $this->embedder->embed($item);

    $proposing = new class($item->getKey()) implements VectorStore
    {
        public function __construct(private readonly string $id) {}

        public function key(): string
        {
            return 'proposing';
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function upsert(string $ownerType, string $ownerId, array $vector): void {}

        public function forget(string $ownerType, string $ownerId): void {}

        public function search(string $ownerType, array $vector, int $limit): array
        {
            return [new VectorMatch($this->id, 0.1)];
        }
    };

    $retriever = new MemoryRetriever($this->embeddings, $proposing, app(AuditLogger::class));

    $results = $retriever->retrieve($this->scopes, MemoryQuery::for('reverting a release'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->item->getKey())->toBe($item->getKey())
        ->and($results[0]->strategy)->toBe(MemoryRetriever::STRATEGY_HYBRID);
});

it('works with no store and no embedding provider at all', function (): void {
    $item = vectorMemory('rollback the deploy immediately');

    $bare = new MemoryRetriever;

    expect($bare->retrieve($this->scopes, MemoryQuery::for('rollback deploy')))
        ->toHaveCount(1)
        ->and($bare->retrieve($this->scopes, MemoryQuery::for('rollback deploy'))[0]->item->getKey())
        ->toBe($item->getKey());
});

it('resolves no vector store by default', function (): void {
    expect(app(VectorStore::class))->toBeNull();
});

it('resolves the database store when configured', function (): void {
    config()->set('pandora.memory.vector_store', 'database');
    app()->forgetInstance(VectorStore::class);

    expect(app(VectorStore::class))->toBeInstanceOf(DatabaseVectorStore::class);
});

it('resolves nothing for an unrecognised store name rather than throwing', function (): void {
    // A typo in configuration should cost recall, not availability.
    config()->set('pandora.memory.vector_store', 'qdrant-typo');
    app()->forgetInstance(VectorStore::class);

    expect(app(VectorStore::class))->toBeNull();
});
