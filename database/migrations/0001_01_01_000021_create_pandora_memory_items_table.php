<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Pandora\Support\Concerns\ResolvesPandoraSchema;

return new class extends Migration
{
    use ResolvesPandoraSchema;

    public function up(): void
    {
        $this->schema()->create($this->table('memory_items'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            // Visibility. `scope` + `scope_id` together are the constraint every
            // retrieval is built on -- see MemoryScope. `scope_id` is a string
            // because a user key belongs to the host and we never assume its type.
            $table->string('scope', 32);
            $table->string('scope_id')->nullable();

            // Which agent may see an agent-scoped item, and which agent wrote
            // any item. Nullable: an operator-entered tenant fact has no agent.
            $table->char('agent_id', 26)->nullable();

            $table->string('type', 32);
            $table->string('title', 191)->nullable();
            $table->text('content');

            // Structured form, when the memory has one. Never queried in a
            // WHERE -- anything filtered often gets promoted to a column.
            $table->json('structured')->nullable();

            $table->string('source', 32);
            $table->char('source_run_id', 26)->nullable();
            $table->json('provenance')->nullable();

            // 0..100. An integer because a float that means "fairly sure"
            // invites arithmetic nobody can justify.
            $table->unsignedTinyInteger('confidence')->default(100);

            $table->string('sensitivity', 32)->default('normal');
            $table->string('status', 32)->default('active');

            // The approval a sensitive memory is waiting on, when suggested.
            $table->char('approval_id', 26)->nullable();

            $table->timestamp('expires_at')->nullable();

            // Set when this item has a vector. Cleared when it is forgotten --
            // a soft-deleted row with a live vector is still findable by the
            // path that matters.
            $table->char('embedding_id', 26)->nullable();

            // Cheap recency signal for ranking without a second table.
            $table->timestamp('last_retrieved_at')->nullable();
            $table->unsignedInteger('retrieval_count')->default(0);

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The retrieval index. Column order matches the predicate the
            // resolver builds: tenant, then scope pair, then status.
            $table->index(['tenant_id', 'scope', 'scope_id', 'status'], 'pandora_memory_scope_idx');
            $table->index(['tenant_id', 'agent_id', 'type'], 'pandora_memory_agent_type_idx');
            $table->index(['tenant_id', 'status', 'expires_at'], 'pandora_memory_expiry_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('memory_items'));
    }
};
