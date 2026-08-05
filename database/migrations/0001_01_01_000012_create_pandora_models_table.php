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
        $this->schema()->create($this->table('models'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            $table->string('provider_key', 100);
            $table->string('model_key', 191);
            $table->string('display_name')->nullable();

            $table->unsignedInteger('context_limit')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();

            // Queried BEFORE routing, so a vision request never reaches a
            // text-only model.
            $table->boolean('supports_streaming')->default(true);
            $table->boolean('supports_tools')->default(false);
            $table->boolean('supports_structured_output')->default(false);
            $table->boolean('supports_vision')->default(false);
            $table->boolean('supports_audio')->default(false);
            $table->boolean('supports_embeddings')->default(false);

            // Per million tokens, in `currency`. Null means UNPRICED, which is
            // a real answer: a cost of null is honest, a cost of zero is a lie.
            $table->decimal('input_price_per_million', 12, 6)->nullable();
            $table->decimal('output_price_per_million', 12, 6)->nullable();
            $table->decimal('cached_input_price_per_million', 12, 6)->nullable();
            $table->decimal('cache_write_price_per_million', 12, 6)->nullable();
            $table->char('currency', 3)->default('USD');

            // Pricing goes stale, and a silently stale estimate is worse than
            // no estimate. Both are required alongside a price.
            $table->string('pricing_source')->nullable();
            $table->date('pricing_date')->nullable();

            $table->timestamp('deprecated_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('synced_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['provider_key', 'model_key'], 'pandora_models_provider_model_unq');
            $table->index(['provider_key', 'enabled'], 'pandora_models_provider_enabled_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('models'));
    }
};
