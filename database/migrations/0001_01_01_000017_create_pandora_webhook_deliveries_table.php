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
        $this->schema()->create($this->table('webhook_deliveries'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('automation_id', 26);
            $table->char('run_id', 26)->nullable();

            /*
             * The replay nonce.
             *
             * A timestamp tolerance alone lets the same request through as
             * many times as you like inside the window, and the window has to
             * be generous enough to survive clock skew. Remembering the
             * signature is the only defence that holds behind a load balancer
             * where no single process sees every delivery.
             *
             * 191 characters: a hex SHA-256 is 64, and the column is in a
             * unique index that has to fit MySQL's key limit.
             */
            $table->string('signature', 191);

            // accepted | rejected
            $table->string('status', 32)->default('accepted');
            $table->string('reason', 191)->nullable();

            $table->string('source_ip', 45)->nullable();
            $table->unsignedInteger('payload_bytes')->default(0);
            // Redacted through the same Redactor as every other stored payload.
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->unique(['automation_id', 'signature'], 'pandora_webhook_deliveries_replay_uq');
            $table->index(['automation_id', 'created_at'], 'pandora_webhook_deliveries_history_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('webhook_deliveries'));
    }
};
