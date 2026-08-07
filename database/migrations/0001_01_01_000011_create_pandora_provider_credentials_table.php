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
        $this->schema()->create($this->table('provider_credentials'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            // Scope. Both null is a deployment-wide credential; a tenant id
            // narrows it to that tenant; an agent id narrows it further still.
            $table->string('tenant_id', 191)->nullable();
            $table->char('agent_id', 26)->nullable();

            $table->string('provider_key', 100);
            $table->string('label')->nullable();

            // Encrypted with the application key. Nothing reads this column
            // directly -- the cast is the only door, and the resolver is the
            // only thing that opens it.
            $table->text('secret');

            // A non-reversible fingerprint, so an operator can tell WHICH key
            // is installed, and whether two environments share one, without
            // ever being shown the key itself.
            $table->string('fingerprint', 32);

            $table->unsignedInteger('version')->default(1);

            // Set when a newer version supersedes this one. Until it passes,
            // the old credential still works -- rotation without a moment
            // where every worker fails at once.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->string('created_by_type')->nullable();
            $table->string('created_by_id')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // Resolution reads by scope and provider; both indexes are on
            // short columns on purpose (see tests/Database/PortabilityTest).
            $table->index(['tenant_id', 'provider_key'], 'pandora_credentials_tenant_idx');
            $table->index(['agent_id', 'provider_key'], 'pandora_credentials_agent_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('provider_credentials'));
    }
};
