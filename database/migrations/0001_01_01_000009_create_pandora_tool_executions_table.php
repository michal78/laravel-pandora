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
        $this->schema()->create($this->table('tool_executions'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('run_id', 26);
            $table->char('run_step_id', 26)->nullable();

            // Explicit lengths for the same reason as on `approvals`: these
            // columns appear in composite indexes, and utf8mb4 varchar(255) is
            // 1020 bytes apiece against a 3072-byte InnoDB key limit.
            $table->string('tool_name', 128);
            $table->string('tool_version', 32)->default('1.0');

            // The provider's id for this call. Ties the execution to the
            // assistant message that requested it and to the result message
            // that answers it.
            $table->string('tool_call_id');

            // What will actually be executed, still needed after a pause of
            // three days, so it is stored as-is rather than redacted.
            // `sanitized_arguments` is what the UI, the trace and the audit
            // log are allowed to show.
            $table->json('arguments')->nullable();
            $table->json('sanitized_arguments')->nullable();
            $table->boolean('arguments_modified')->default(false);
            $table->json('argument_diff')->nullable();

            $table->json('result')->nullable();
            $table->json('sanitized_result')->nullable();

            $table->string('status', 32)->default('pending');
            $table->string('risk_level', 32)->default('low');
            $table->string('decided_by', 32)->nullable();
            $table->text('decision_reason')->nullable();

            $table->boolean('required_approval')->default(false);
            $table->char('approval_id', 26)->nullable();
            $table->string('approver_type')->nullable();
            $table->string('approver_id')->nullable();

            // Derived from (run, tool, canonicalised arguments, attempt), so a
            // retried job recognises work it has already done rather than
            // applying a side effect twice.
            $table->string('idempotency_key');
            $table->unsignedInteger('attempt')->default(1);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'tool_call_id', 'attempt'], 'pandora_tool_exec_call_unq');
            $table->index(['tenant_id', 'tool_name', 'created_at'], 'pandora_tool_exec_tenant_name_idx');
            // The fan-in query: how many of this run's calls are still open?
            $table->index(['run_id', 'status'], 'pandora_tool_exec_run_status_idx');
            $table->index(['run_id', 'idempotency_key'], 'pandora_tool_exec_idem_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('tool_executions'));
    }
};
