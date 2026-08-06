<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory\Vector;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Pandora\Pandora\Contracts\VectorStore;
use Pandora\Pandora\Memory\Embedding;

/**
 * Nearest-neighbour search using PostgreSQL's `pgvector` extension.
 *
 * Keeps a native `vector` column alongside the portable JSON one rather than
 * replacing it. The duplication is deliberate: the JSON column is what makes
 * changing adapters a configuration change instead of a re-embedding project,
 * and it is also the only copy an operator can read without the extension
 * installed. Disk is cheaper than a migration nobody can roll back.
 *
 * Availability is checked rather than assumed. `pgvector` is an extension a
 * managed Postgres may not offer, and the failure mode without this check is
 * every retrieval throwing rather than quietly falling back to lexical.
 */
final class PgvectorStore implements VectorStore
{
    private ?bool $available = null;

    public function __construct(
        // `Connection`, not `ConnectionInterface`: this adapter has to ask
        // which driver it is talking to, and `getDriverName()` lives on the
        // concrete class. An adapter that could not tell PostgreSQL from
        // MySQL would issue pgvector syntax at MySQL and call the resulting
        // exception "unavailable".
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function key(): string
    {
        return 'pgvector';
    }

    public function isAvailable(): bool
    {
        // Memoised per instance. This runs on the retrieval path, and asking
        // the catalogue on every query would add a round trip to every model
        // request to answer a question whose answer changes at deploy time.
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $driver = $this->connection->getDriverName();

            if ($driver !== 'pgsql') {
                return $this->available = false;
            }

            $extension = $this->connection->selectOne(
                "select 1 as present from pg_extension where extname = 'vector'",
            );

            $column = $this->connection->selectOne(
                'select 1 as present from information_schema.columns '.
                'where table_name = ? and column_name = ?',
                [$this->table, 'vector_native'],
            );

            return $this->available = $extension !== null && $column !== null;
        } catch (QueryException) {
            return $this->available = false;
        }
    }

    public function upsert(string $ownerType, string $ownerId, array $vector): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $this->connection->update(
            "update {$this->table} set vector_native = ?::vector ".
            'where owner_type = ? and owner_id = ?',
            [$this->literal($vector), $ownerType, $ownerId],
        );
    }

    public function forget(string $ownerType, string $ownerId): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        // Null rather than delete: the embedding row itself is owned by the
        // embedder, and a store that deleted rows out from under it would
        // make "is this embedded?" answerable two different ways.
        $this->connection->update(
            "update {$this->table} set vector_native = null ".
            'where owner_type = ? and owner_id = ?',
            [$ownerType, $ownerId],
        );
    }

    public function search(string $ownerType, array $vector, int $limit): array
    {
        if ($vector === [] || ! $this->isAvailable()) {
            return [];
        }

        // `<=>` is cosine distance. Parameterised, and the limit is cast to an
        // int rather than bound, because PostgreSQL will not accept a
        // placeholder in LIMIT through every driver configuration.
        $rows = $this->connection->select(
            "select owner_id, vector_native <=> ?::vector as distance from {$this->table} ".
            'where owner_type = ? and vector_native is not null and dimensions = ? '.
            'order by distance asc limit '.max(1, $limit),
            [$this->literal($vector), $ownerType, count($vector)],
        );

        $matches = [];

        foreach ($rows as $row) {
            /** @var array{owner_id: string, distance: float|string} $fields */
            $fields = (array) $row;

            $matches[] = new VectorMatch(
                ownerId: (string) $fields['owner_id'],
                distance: (float) $fields['distance'],
            );
        }

        return $matches;
    }

    /**
     * Backfill native vectors from the portable column.
     *
     * Used after enabling the extension on an installation that already has
     * embeddings, so turning pgvector on does not mean re-embedding (and
     * re-paying for) a corpus that is already stored.
     */
    public function backfill(int $chunk = 500): int
    {
        if (! $this->isAvailable()) {
            return 0;
        }

        $written = 0;

        Embedding::query()
            ->whereRaw('vector_native is null')
            ->orderBy('id')
            ->chunk($chunk, function ($embeddings) use (&$written): void {
                foreach ($embeddings as $embedding) {
                    $this->upsert($embedding->owner_type, $embedding->owner_id, $embedding->vector);
                    $written++;
                }
            });

        return $written;
    }

    /**
     * @param list<float> $vector
     */
    private function literal(array $vector): string
    {
        // pgvector's text input format. json_encode is not used because it can
        // emit `1.0e-5`, which the vector parser rejects.
        return '['.implode(',', array_map(
            static fn (float $v): string => rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.') ?: '0',
            $vector,
        )).']';
    }
}
