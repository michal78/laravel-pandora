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
        $this->schema()->create($this->table('observations'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('agent_id', 26);
            // The run that proposed it. An observation with no provenance is
            // an anonymous instruction, and nobody should promote one of those.
            $table->char('run_id', 26)->nullable();

            $table->string('title', 191);
            // What the agent would like to be asked, next time. Promoted
            // verbatim into the automation's prompt.
            $table->text('proposal');
            $table->text('rationale')->nullable();

            // A suggested schedule the agent may express and a human may
            // ignore. Deliberately advisory: the agent proposes when, the
            // human decides whether.
            $table->string('suggested_cron', 120)->nullable();

            // pending | promoted | dismissed | expired
            $table->string('status', 32)->default('pending');

            $table->char('automation_id', 26)->nullable();
            $table->string('resolved_by_type')->nullable();
            $table->string('resolved_by_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at'], 'pandora_observations_status_idx');
            $table->index(['tenant_id', 'agent_id'], 'pandora_observations_agent_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('observations'));
    }
};
