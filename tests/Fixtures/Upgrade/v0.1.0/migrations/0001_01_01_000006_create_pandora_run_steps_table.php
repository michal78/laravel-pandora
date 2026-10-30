<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

/**
 * Append-only. No `updated_at` column, because rows are never updated -- the
 * step list IS the trace, and a trace that can be rewritten is not a trace.
 */
return new class extends Migration
{
    use ResolvesPandoraSchema;

    public function up(): void
    {
        $this->schema()->create($this->table('run_steps'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->char('run_id', 26);

            $table->unsignedInteger('sequence');
            $table->string('type');
            $table->string('status')->default('started');
            $table->string('label')->nullable();

            // Redacted at construction time, never at serialisation.
            $table->json('payload')->nullable();
            // Unmapped vendor fields, admin-visible only.
            $table->json('raw_meta')->nullable();

            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cost_minor')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['run_id', 'sequence'], 'pandora_run_steps_seq_unq');
            $table->index(['run_id', 'type'], 'pandora_run_steps_type_idx');
            $table->index(['tenant_id', 'created_at'], 'pandora_run_steps_prune_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('run_steps'));
    }
};
