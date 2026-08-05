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
        $this->schema()->create($this->table('usage_records'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id', 191)->nullable();

            // Every scope a budget can be drawn around, denormalised onto the
            // row. A usage query runs on every page of the control center and
            // during every budget check; making it join four tables to answer
            // "what has this agent spent this month" would be a tax on the
            // hot path forever.
            $table->char('run_id', 26)->nullable();
            $table->char('agent_id', 26)->nullable();
            $table->char('conversation_id', 26)->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();

            $table->string('provider_key', 100);
            $table->string('model_key', 191);

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('cached_output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('requests')->default(1);
            $table->unsignedInteger('duration_ms')->default(0);

            // Millionths of the currency unit. Null means UNPRICED, which is
            // a different fact from free, and the two must not be summed
            // together into a total that looks authoritative.
            $table->bigInteger('cost_micro')->nullable();
            $table->char('currency', 3)->default('USD');

            // Copied onto the record rather than looked up later: a cost from
            // today must still say what it was based on after somebody edits
            // the catalog tomorrow.
            $table->string('pricing_source')->nullable();
            $table->date('pricing_date')->nullable();
            $table->boolean('pricing_stale')->default(false);

            $table->timestamp('occurred_at');

            // Append-only: a usage record is a measurement, and a measurement
            // that can be edited is not evidence of anything.
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'occurred_at'], 'pandora_usage_tenant_time_idx');
            $table->index(['agent_id', 'occurred_at'], 'pandora_usage_agent_time_idx');
            $table->index(['conversation_id'], 'pandora_usage_conversation_idx');
            $table->index(['run_id'], 'pandora_usage_run_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('usage_records'));
    }
};
