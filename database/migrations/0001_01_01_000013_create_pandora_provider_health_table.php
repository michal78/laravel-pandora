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
        $this->schema()->create($this->table('provider_health'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            $table->string('provider_key', 100)->unique('pandora_provider_health_key_unq');

            $table->string('status', 20)->default('unknown');
            $table->unsignedInteger('latency_ms')->nullable();

            // Degradation is decided on a RUN of failures, not on one. A
            // single timeout is weather; three in a row is a broken provider.
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);

            $table->text('last_error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('degraded_since')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('provider_health'));
    }
};
