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
        $this->schema()->create($this->table('automations'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('agent_id', 26);

            $table->string('name', 120);
            $table->string('slug', 120);
            $table->text('description')->nullable();

            // one_off | cron | interval | event | webhook | heartbeat
            $table->string('trigger_type', 32);

            $table->string('cron_expression', 120)->nullable();
            $table->unsignedInteger('interval_seconds')->nullable();
            $table->timestamp('run_at')->nullable();

            // The automation's own timezone, not the server's. A "9am daily"
            // report that moves twice a year because the server is in UTC is
            // the bug this column exists to prevent.
            $table->string('timezone', 64)->default('UTC');

            $table->string('event_class')->nullable();

            // A NAME from the configured condition registry plus its
            // arguments -- never a callable, never a class name from a row.
            // Same rule as tools: an arbitrary callable stored in the database
            // is remote code execution with extra steps.
            $table->json('condition')->nullable();

            // What the agent is told when this fires.
            $table->text('prompt')->nullable();
            $table->json('context')->nullable();

            // Where the result goes. Stored now, honoured in Phase 7.
            $table->json('delivery')->nullable();

            $table->string('concurrency_policy', 32)->default('skip');
            $table->string('misfire_policy', 32)->default('skip');
            $table->json('retry_policy')->nullable();

            // Clamped to the agent's level on every run -- never the reverse.
            $table->string('autonomy_level', 32)->default('observe_only');

            // How often this may wake, per rolling window. A token budget
            // does not catch an automation that wakes every minute and
            // returns immediately.
            $table->unsignedInteger('autonomy_budget_runs')->nullable();
            $table->unsignedInteger('autonomy_budget_window_seconds')->default(86400);

            // Webhook automations only. Encrypted; never selected into a
            // response or a broadcast.
            $table->text('webhook_secret')->nullable();

            $table->boolean('enabled')->default(false);

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->char('last_run_id', 26)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->text('disabled_reason')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug'], 'pandora_automations_tenant_slug_uq');

            // The scheduler's only query. Deliberately narrow: it runs every
            // minute forever, on a table an operator will grow without
            // thinking about indexes.
            $table->index(['enabled', 'next_run_at'], 'pandora_automations_due_idx');
            $table->index(['tenant_id', 'agent_id'], 'pandora_automations_agent_idx');
            // The event dispatcher's lookup.
            $table->index(['enabled', 'event_class'], 'pandora_automations_event_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('automations'));
    }
};
