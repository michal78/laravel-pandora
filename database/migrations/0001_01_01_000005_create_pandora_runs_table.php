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
        $this->schema()->create($this->table('runs'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('agent_id', 26);
            $table->char('conversation_id', 26)->nullable();
            $table->char('session_id', 26);

            // Delegation tree.
            $table->char('parent_run_id', 26)->nullable();
            $table->unsignedTinyInteger('delegation_depth')->default(0);

            $table->string('state')->default('pending');
            $table->string('trigger_type')->default('user_message');
            $table->string('trigger_id')->nullable();
            $table->char('correlation_id', 26);
            $table->string('idempotency_key')->nullable();

            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();

            $table->string('provider_key')->nullable();
            $table->string('model_key')->nullable();

            $table->longText('input')->nullable();
            $table->longText('output')->nullable();

            $table->unsignedInteger('iterations')->default(0);
            $table->unsignedInteger('tool_calls_count')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency', 3)->default('USD');

            // Database-side ownership: survives a cache flush and is the
            // authority when the lock driver cannot be trusted.
            $table->char('owner_token', 26)->nullable();
            $table->timestamp('owner_expires_at')->nullable();

            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('deadline_at')->nullable();

            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'state', 'created_at'], 'pandora_runs_tenant_state_idx');
            $table->index(['tenant_id', 'agent_id', 'created_at'], 'pandora_runs_tenant_agent_idx');
            $table->index(['conversation_id', 'created_at'], 'pandora_runs_conv_idx');
            $table->index(['parent_run_id'], 'pandora_runs_parent_idx');
            // Stall detection: running runs whose ownership lease has expired.
            $table->index(['state', 'owner_expires_at'], 'pandora_runs_stall_idx');
            $table->unique(['tenant_id', 'idempotency_key'], 'pandora_runs_idempotency_unq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('runs'));
    }
};
