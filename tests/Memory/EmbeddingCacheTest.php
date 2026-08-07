<?php

declare(strict_types=1);

use Pandora\Contracts\EmbeddingProvider;
use Pandora\Memory\Embedding;
use Pandora\Memory\Embeddings\HashEmbeddingProvider;
use Pandora\Memory\Embeddings\MemoryEmbedder;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryItem;
use Pandora\Memory\Vector\DatabaseVectorStore;

/**
 * Phase 5, criterion 18 -- do not pay twice for the same vector, and never
 * mix two vector spaces in one column.
 *
 * The first half is money. The second half is correctness, and it is the one
 * with no symptom: vectors from two different models in the same column make
 * every distance meaningless, and nothing about the numbers would reveal it.
 */
$counting = new class(new HashEmbeddingProvider(32)) implements EmbeddingProvider
{
    public int $calls = 0;

    public function __construct(private readonly HashEmbeddingProvider $inner) {}

    public function key(): string
    {
        return 'counting';
    }

    public function model(): string
    {
        return 'counting-v1';
    }

    public function dimensions(): int
    {
        return $this->inner->dimensions();
    }

    public function embed(string $text): array
    {
        $this->calls++;

        return $this->inner->embed($text);
    }

    public function embedBatch(array $texts): array
    {
        return array_map($this->embed(...), $texts);
    }
};

beforeEach(function () use ($counting): void {
    $counting->calls = 0;
    $this->provider = $counting;
    $this->embedder = new MemoryEmbedder($counting, new DatabaseVectorStore);
});

function cacheableMemory(string $content = 'The deploy window is Thursday.'): MemoryItem
{
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'type' => MemoryType::UserFact->value,
        'content' => $content,
        'source' => MemorySource::User->value,
    ]);

    return $item;
}

it('does not re-embed unchanged content', function (): void {
    $item = cacheableMemory();

    expect($this->embedder->embed($item))->toBeTrue()
        ->and($this->provider->calls)->toBe(1);

    expect($this->embedder->embed($item))->toBeFalse()
        ->and($this->embedder->embed($item))->toBeFalse()
        ->and($this->provider->calls)->toBe(1);
});

it('re-embeds when the content changes', function (): void {
    $item = cacheableMemory();
    $this->embedder->embed($item);

    $item->update(['content' => 'The deploy window moved to Friday.']);

    expect($this->embedder->embed($item))->toBeTrue()
        ->and($this->provider->calls)->toBe(2)
        ->and(Embedding::query()->count())->toBe(1);
});

it('is not fooled by a whitespace-only change', function (): void {
    $item = cacheableMemory();
    $this->embedder->embed($item);

    $item->update(['content' => '  The deploy window is Thursday.  ']);

    expect($this->embedder->embed($item))->toBeFalse()
        ->and($this->provider->calls)->toBe(1);
});

it('re-embeds under a new model rather than reusing the old vector', function (): void {
    $item = cacheableMemory();
    $this->embedder->embed($item);

    // Same text, different model. Reusing the stored vector here is worse than
    // spending the money: it silently mixes two vector spaces.
    $newModel = new MemoryEmbedder(new HashEmbeddingProvider(64), new DatabaseVectorStore);

    expect($newModel->embed($item))->toBeTrue();

    $embeddings = Embedding::query()->where('owner_id', $item->getKey())->get();

    expect($embeddings)->toHaveCount(2)
        ->and($embeddings->pluck('model_key')->all())
        ->toEqualCanonicalizing(['counting-v1', 'hash-64']);
});

it('links the memory to its embedding without looking like an edit', function (): void {
    $item = cacheableMemory();
    $changedAt = $item->refresh()->updated_at;

    $this->embedder->embed($item);
    $item->refresh();

    expect($item->embedding_id)->not->toBeNull()
        // A re-embed that bumped updated_at would show on the Memory page as
        // somebody having changed what the memory says.
        ->and($item->updated_at?->equalTo($changedAt))->toBeTrue();
});

it('relinks an embedding written by a run that died before linking it', function (): void {
    $item = cacheableMemory();
    $this->embedder->embed($item);

    $item->forceFill(['embedding_id' => null])->saveQuietly();

    expect($this->embedder->embed($item))->toBeFalse()
        ->and($item->refresh()->embedding_id)->not->toBeNull()
        ->and($this->provider->calls)->toBe(1);
});

it('deletes the vector when a memory is forgotten', function (): void {
    $item = cacheableMemory();
    $this->embedder->embed($item);

    expect(Embedding::query()->count())->toBe(1);

    $this->embedder->forget($item);

    // A soft-deleted row whose vector is still indexed remains findable by the
    // path that matters, so this is the deletion rather than a tidy-up.
    expect(Embedding::query()->count())->toBe(0)
        ->and($item->refresh()->embedding_id)->toBeNull();
});

it('produces the same vector for the same text every time', function (): void {
    $provider = new HashEmbeddingProvider(32);

    expect($provider->embed('the deploy window'))->toBe($provider->embed('the deploy window'))
        ->and($provider->embed('the deploy window'))->not->toBe($provider->embed('something else'));
});

it('returns a zero vector for text with no tokens', function (): void {
    $provider = new HashEmbeddingProvider(8);

    expect($provider->embed('  the and of  '))->toBe(array_fill(0, 8, 0.0));
});

it('normalises so a long text does not outrank a short one by length alone', function (): void {
    $provider = new HashEmbeddingProvider(64);

    $norm = static fn (array $v): float => sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $v)));

    expect($norm($provider->embed('deploy')))->toBeGreaterThan(0.99)
        ->and($norm($provider->embed(str_repeat('deploy rollback runbook thursday ', 50))))
        ->toBeLessThan(1.01);
});
