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
        $this->schema()->create($this->table('agents'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('avatar_path')->nullable();
            $table->boolean('enabled')->default(true);

            // Set when the agent originates from an AgentDefinition class.
            // Class-defined fields are authoritative over database edits.
            $table->string('definition_class')->nullable();

            $table->text('system_instructions')->nullable();
            $table->text('role_instructions')->nullable();

            $table->string('default_provider')->nullable();
            $table->string('default_model')->nullable();
            $table->json('fallback_models')->nullable();
            $table->json('provider_options')->nullable();

            $table->unsignedInteger('max_iterations')->default(12);
            $table->unsignedInteger('max_tool_calls')->default(30);
            $table->unsignedInteger('max_duration_seconds')->default(600);
            $table->unsignedInteger('context_budget_tokens')->default(24000);
            $table->unsignedBigInteger('token_budget')->nullable();
            $table->unsignedBigInteger('cost_budget_minor')->nullable();
            $table->char('currency', 3)->default('USD');

            $table->string('autonomy_level')->default('suggest');
            $table->json('memory_policy')->nullable();
            $table->json('tool_policy')->nullable();
            $table->json('approval_policy')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug'], 'pandora_agents_tenant_slug_unq');
            $table->index(['tenant_id', 'enabled'], 'pandora_agents_tenant_enabled_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('agents'));
    }
};
