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
        $this->schema()->create($this->table('approvals'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('run_id', 26);
            $table->char('tool_execution_id', 26)->nullable();

            $table->string('tool_name');
            $table->string('tool_version')->default('1.0');
            $table->string('risk_level')->default('high');

            // A human summary of THIS call, not of the tool in general: an
            // approver deciding on "Refund £42.00 to order 1234" is making a
            // different decision from one shown "refund_order".
            $table->text('summary');

            // Only ever the sanitized copy. An approval card is a UI surface,
            // and the raw arguments live on the execution row.
            $table->json('sanitized_arguments')->nullable();
            $table->json('proposed_modifications')->nullable();

            // once | run | remembered
            $table->string('scope')->default('once');

            // approval | confirmation -- who may resolve it differs.
            $table->string('kind')->default('approval');

            // pending | approved | denied | expired | cancelled
            $table->string('status')->default('pending');

            $table->string('requested_by_type')->nullable();
            $table->string('requested_by_id')->nullable();
            $table->string('resolved_by_type')->nullable();
            $table->string('resolved_by_id')->nullable();
            $table->text('comment')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('resolved_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'expires_at'], 'pandora_approvals_tenant_status_idx');
            $table->index(['run_id', 'status'], 'pandora_approvals_run_status_idx');
            // Remembered approvals are looked up by what they cover.
            $table->index(['tenant_id', 'tool_name', 'scope', 'status'], 'pandora_approvals_remembered_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('approvals'));
    }
};
