<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Pandora\Support\Concerns\ResolvesPandoraSchema;

/**
 * Sessions are Pandora's SECURITY BOUNDARY, not a routing selector.
 *
 * `isolation_key` is a deterministic hash of (tenant, agent, actor, channel,
 * participant, origin). The same tuple always maps to the same session and a
 * different tuple never can -- which is what stops one user's private context
 * reaching another user who happens to share a conversation or a channel inbox.
 */
return new class extends Migration
{
    use ResolvesPandoraSchema;

    public function up(): void
    {
        $this->schema()->create($this->table('sessions'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('conversation_id', 26)->nullable();
            $table->char('agent_id', 26);

            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();

            $table->string('channel')->default('web');
            $table->string('channel_participant_id')->nullable();
            $table->string('origin')->default('web');

            $table->string('isolation_key', 64);

            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'isolation_key'], 'pandora_sessions_isolation_unq');
            $table->index(['conversation_id'], 'pandora_sessions_conv_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('sessions'));
    }
};
