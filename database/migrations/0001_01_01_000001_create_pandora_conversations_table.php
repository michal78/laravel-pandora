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
        $this->schema()->create($this->table('conversations'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('agent_id', 26)->nullable();
            $table->string('title')->nullable();
            $table->string('channel')->default('web');
            $table->string('status')->default('active');
            $table->boolean('pinned')->default(false);
            $table->json('tags')->nullable();

            // Forking: a child records where it diverged from its parent.
            $table->char('parent_conversation_id', 26)->nullable();
            $table->char('forked_at_message_id', 26)->nullable();

            $table->string('provider_override')->nullable();
            $table->string('model_override')->nullable();

            // Host user reference: string + morph, never a foreign key -- we
            // never assume the host's key type or that it shares a database.
            $table->string('created_by_type')->nullable();
            $table->string('created_by_id')->nullable();

            $table->timestamp('last_activity_at')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'last_activity_at'], 'pandora_convs_tenant_status_idx');
            $table->index(['tenant_id', 'agent_id'], 'pandora_convs_tenant_agent_idx');
            $table->index(['created_by_type', 'created_by_id'], 'pandora_convs_creator_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('conversations'));
    }
};
