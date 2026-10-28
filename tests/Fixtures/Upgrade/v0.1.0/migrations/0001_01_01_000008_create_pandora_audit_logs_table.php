<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

/**
 * Append-only security record, conceptually separate from application logs and
 * from run traces. Records what was ATTEMPTED, whether or not it succeeded.
 */
return new class extends Migration
{
    use ResolvesPandoraSchema;

    public function up(): void
    {
        $this->schema()->create($this->table('audit_logs'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->char('correlation_id', 26)->nullable();

            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();

            $table->string('action');
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->char('run_id', 26)->nullable();

            $table->string('severity')->default('info');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at'], 'pandora_audit_tenant_idx');
            $table->index(['tenant_id', 'action', 'created_at'], 'pandora_audit_action_idx');
            $table->index(['correlation_id'], 'pandora_audit_correlation_idx');
            $table->index(['target_type', 'target_id'], 'pandora_audit_target_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('audit_logs'));
    }
};
