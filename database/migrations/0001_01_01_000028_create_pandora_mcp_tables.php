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
        $this->schema()->create($this->table('mcp_servers'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->string('name', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();

            // The namespace remote tools are published under, and the reason
            // it lives HERE rather than being read from the server: a server's
            // own idea of its name is attacker-controlled input being used as
            // an identity. This column is written by an operator (ADR-0014).
            $table->string('namespace', 64);

            // 'http' | 'sse' | 'stdio'. stdio is refused unless a deployment
            // explicitly enables it, because it means executing a local binary
            // named by this row.
            $table->string('transport', 16)->default('http');
            $table->string('endpoint', 2048)->nullable();

            // Only ever read for the stdio transport, and only when that
            // transport is enabled. Stored so an operator can see what would
            // run, not so anything runs by default.
            $table->string('command', 1024)->nullable();
            $table->json('command_arguments')->nullable();

            // NO credential column. The secret lives in
            // `pandora_provider_credentials` under this key, encrypted with
            // the application key, resolved by the Phase 3 resolver
            // (ADR-0014). A second secret store is a second thing to leak.
            $table->string('credential_key', 100)->nullable();

            // Probed like a provider. An unhealthy server's tools are
            // unavailable rather than slow.
            $table->string('health', 16)->default('unknown');
            $table->text('health_message')->nullable();
            $table->timestamp('last_probed_at')->nullable();
            $table->timestamp('last_discovered_at')->nullable();

            $table->unsignedInteger('timeout_seconds')->default(30);

            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug'], 'pandora_mcp_servers_slug_uq');
            $table->unique(['tenant_id', 'namespace'], 'pandora_mcp_servers_ns_uq');
        });

        $this->schema()->create($this->table('mcp_tools'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('server_id', 26);

            // What the server called it, and what we call it. The second is
            // derived locally from the server's namespace column; the first is
            // recorded because it is what gets sent back over the wire.
            $table->string('remote_name', 191);
            $table->string('namespaced_name', 255);

            // Untrusted content, both of them. The description is bounded at
            // write time and escaped at render time; neither ever occupies an
            // instruction position in a prompt.
            $table->text('description')->nullable();
            $table->json('input_schema')->nullable();

            // Canonical JSON over remote name, namespaced name, description
            // AND input schema. Hashing only the schema misses the injection
            // vector: a server may keep every parameter and rewrite its
            // description into an instruction (ADR-0014).
            $table->string('schema_hash', 64);

            // Set when a re-hash disagreed with what was approved. The tool
            // fails closed until a human looks at it.
            $table->timestamp('schema_changed_at')->nullable();
            $table->string('previous_schema_hash', 64)->nullable();

            $table->boolean('available')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'remote_name'], 'pandora_mcp_tools_uq');
            $table->index(['tenant_id', 'namespaced_name'], 'pandora_mcp_tools_name_idx');
        });

        // Approval is per agent, per tool. Never per server: "trust this
        // server" is a blanket that keeps covering tools added after it was
        // issued, and two agents on one server are two different blast radii.
        $this->schema()->create($this->table('mcp_tool_approvals'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('agent_id', 26);
            $table->char('mcp_tool_id', 26);

            // The hash AS APPROVED. The call path re-hashes and compares
            // against this, so an approval is of a specific description and a
            // specific schema rather than of a name.
            $table->string('approved_schema_hash', 64);

            $table->timestamp('approved_at');
            $table->string('approved_by_type')->nullable();
            $table->string('approved_by_id')->nullable();

            // Kept rather than deleted on revocation, so "this was approved
            // once and taken away" is distinguishable from "never approved".
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 191)->nullable();

            $table->timestamps();

            $table->unique(['agent_id', 'mcp_tool_id'], 'pandora_mcp_approvals_uq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('mcp_tool_approvals'));
        $this->schema()->dropIfExists($this->table('mcp_tools'));
        $this->schema()->dropIfExists($this->table('mcp_servers'));
    }
};
