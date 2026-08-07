<?php

declare(strict_types=1);

namespace Pandora\Memory\Embeddings;

use Pandora\Contracts\EmbeddingProvider;
use Pandora\Contracts\VectorStore;
use Pandora\Memory\Embedding;
use Pandora\Memory\MemoryItem;

/**
 * Keeps a memory's vector in step with its content.
 *
 * The cache key is `(owner, provider, model)` with a content hash, and all
 * four parts earn their place. Re-embedding unchanged text is money spent to
 * receive the same vector back. Re-using a vector after the model changed is
 * worse than spending the money: two vector spaces in one column makes every
 * distance meaningless, and nothing about the numbers would reveal it.
 */
final class MemoryEmbedder
{
    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly VectorStore $store,
    ) {}

    /**
     * Embed a memory if it is not already embedded with this exact model and
     * this exact content.
     *
     * @return bool whether the provider was actually called
     */
    public function embed(MemoryItem $item): bool
    {
        $text = $item->embeddableText();
        $hash = Embedding::hash($text);

        /** @var Embedding|null $existing */
        $existing = Embedding::query()
            ->where('owner_type', MemoryItem::class)
            ->where('owner_id', $item->getKey())
            ->where('provider_key', $this->provider->key())
            ->where('model_key', $this->provider->model())
            ->first();

        if ($existing !== null && $existing->content_hash === $hash) {
            // Already current. The row is still pointed at, in case a previous
            // run wrote the embedding but died before linking it.
            $this->link($item, $existing);

            return false;
        }

        $vector = $this->provider->embed($text);

        /** @var Embedding $embedding */
        $embedding = Embedding::query()->updateOrCreate(
            [
                'owner_type' => MemoryItem::class,
                'owner_id' => $item->getKey(),
                'provider_key' => $this->provider->key(),
                'model_key' => $this->provider->model(),
            ],
            [
                'dimensions' => $this->provider->dimensions(),
                'vector' => $vector,
                'content_hash' => $hash,
            ],
        );

        $this->store->upsert(MemoryItem::class, (string) $item->getKey(), $vector);

        $this->link($item, $embedding);

        return true;
    }

    /**
     * Remove a memory's vector everywhere it exists.
     *
     * Called when a memory is forgotten. A soft-deleted row whose vector is
     * still indexed remains findable by the path that matters, so this is the
     * deletion rather than a tidy-up after it.
     */
    public function forget(MemoryItem $item): void
    {
        Embedding::query()
            ->where('owner_type', MemoryItem::class)
            ->where('owner_id', $item->getKey())
            ->delete();

        $this->store->forget(MemoryItem::class, (string) $item->getKey());

        if ($item->embedding_id !== null) {
            $item->forceFill(['embedding_id' => null])->saveQuietly();
        }
    }

    private function link(MemoryItem $item, Embedding $embedding): void
    {
        if ($item->embedding_id === (string) $embedding->getKey()) {
            return;
        }

        // Quietly: linking a vector is not a change to what the memory says,
        // and an updated_at bump here would make every re-embed look like an
        // edit on the Memory page.
        $item->forceFill(['embedding_id' => $embedding->getKey()])->saveQuietly();
    }
}
