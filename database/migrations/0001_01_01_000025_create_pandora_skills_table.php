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
        $this->schema()->create($this->table('skills'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->string('name', 191);
            $table->string('slug', 191);
            $table->string('version', 32)->default('1.0.0');
            $table->string('author', 191)->nullable();
            $table->text('description')->nullable();

            // INSTRUCTIONS, never code. ADR-0008. There is no column here that
            // could hold something executable, and that is the design: a skill
            // installed from a manifest that could run is arbitrary code
            // execution driven by a database row.
            $table->longText('instructions');

            $table->json('manifest')->nullable();
            $table->json('trigger_hints')->nullable();
            $table->json('required_tools')->nullable();
            $table->json('required_abilities')->nullable();

            $table->string('source', 32)->default('local');
            $table->string('validation_status', 32)->default('valid');
            $table->json('validation_errors')->nullable();

            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug', 'version'], 'pandora_skills_slug_version_uq');
        });

        $this->schema()->create($this->table('agent_skills'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('agent_id', 26);
            $table->char('skill_id', 26);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['agent_id', 'skill_id'], 'pandora_agent_skills_uq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('agent_skills'));
        $this->schema()->dropIfExists($this->table('skills'));
    }
};
