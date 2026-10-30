<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

/**
 * Adds a native pgvector column, on PostgreSQL only.
 *
 * A column rather than a table, so every portability rule the other tables are
 * held to still applies unchanged and there is no engine-specific table for
 * `PortabilityTest` to reason about. It sits alongside the portable JSON
 * column rather than replacing it: the JSON copy is what makes swapping
 * adapters a configuration change, and it is the only copy readable without
 * the extension installed.
 *
 * Everything here is conditional and non-fatal. `CREATE EXTENSION` needs
 * privileges a managed Postgres may not grant, and a package migration that
 * fails the whole install because an optional accelerator is unavailable has
 * its priorities backwards. If this ends up doing nothing, `PgvectorStore`
 * reports itself unavailable and retrieval stays lexical.
 */
return new class extends Migration
{
    use ResolvesPandoraSchema;

    public function up(): void
    {
        $connection = $this->schema()->getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $table = $this->table('embeddings');

        try {
            $connection->statement('create extension if not exists vector');
        } catch (QueryException) {
            // No privilege, or no extension available on this server. Not an
            // error: the installation simply has no vector acceleration.
            return;
        }

        /** @var int $dimensions */
        $dimensions = config('pandora.memory.embeddings.dimensions', 1536);

        try {
            $connection->statement(
                "alter table {$table} add column if not exists vector_native vector({$dimensions})",
            );

            // HNSW over cosine distance -- the metric `PgvectorStore` queries
            // with. An index built for a different operator class is silently
            // ignored by the planner, which looks exactly like pgvector being
            // slow for no reason.
            $connection->statement(
                "create index if not exists pandora_embeddings_vec_idx on {$table} ".
                'using hnsw (vector_native vector_cosine_ops)',
            );
        } catch (QueryException) {
            // An existing column of a different dimension, most likely. Left
            // alone rather than dropped: it holds vectors somebody paid for.
        }
    }

    public function down(): void
    {
        $connection = $this->schema()->getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $table = $this->table('embeddings');

        try {
            $connection->statement('drop index if exists pandora_embeddings_vec_idx');
            $connection->statement("alter table {$table} drop column if exists vector_native");
        } catch (QueryException) {
            // Nothing to undo.
        }
    }
};
