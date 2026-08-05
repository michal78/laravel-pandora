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
        $this->schema()->create($this->table('automation_runs'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('automation_id', 26);
            $table->char('run_id', 26)->nullable();

            // The occurrence this row IS -- not when it was processed. Two
            // schedulers that both notice the 09:00 occurrence agree on this
            // value, which is what makes the key below collide.
            $table->timestamp('scheduled_for');

            // claimed | dispatched | skipped | refused | failed
            $table->string('status', 32)->default('claimed');
            $table->string('reason', 191)->nullable();

            /*
             * The double-fire guard.
             *
             * Derived deterministically from (automation, occurrence), so two
             * schedulers computing the same due occurrence compute the same
             * key -- and the second INSERT is refused by the database rather
             * than by application code that might not have run yet.
             *
             * The insert IS the claim. Nothing downstream re-checks, because
             * by the time anything downstream runs, the model has been called.
             */
            $table->string('idempotency_key', 128);

            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'idempotency_key'], 'pandora_automation_runs_key_uq');
            $table->index(['automation_id', 'created_at'], 'pandora_automation_runs_history_idx');
            $table->index(['tenant_id', 'status'], 'pandora_automation_runs_status_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('automation_runs'));
    }
};
