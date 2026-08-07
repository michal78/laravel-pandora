<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

return new class extends Migration
{
    use ResolvesPandoraSchema;

    public function up(): void
    {
        $this->schema()->create($this->table('embeddings'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            // Polymorphic by hand rather than by morphs(): the owner id is a
            // ULID char(26) and morphs() would give us a string of the wrong
            // width on some engines.
            $table->string('owner_type', 191);
            $table->char('owner_id', 26);

            $table->string('provider_key', 64);
            $table->string('model_key', 191);
            $table->unsignedSmallInteger('dimensions');

            // The portable default. A pgvector installation writes its own
            // native column alongside and reads from that; this stays as the
            // engine-independent copy so an install can change adapters
            // without re-embedding everything it owns.
            $table->json('vector');

            // sha256 of the exact text embedded. Re-embedding unchanged content
            // is money spent to get the same vector back.
            $table->char('content_hash', 64);

            $table->timestamps();

            // One vector per owner per (provider, model). A changed model must
            // replace rather than accumulate -- two vector spaces in one column
            // makes every distance meaningless.
            $table->unique(['owner_type', 'owner_id', 'provider_key', 'model_key'], 'pandora_embeddings_owner_uq');
            $table->index(['tenant_id', 'content_hash'], 'pandora_embeddings_hash_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('embeddings'));
    }
};
