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
        $this->schema()->create($this->table('workspaces'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->string('name', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();

            // The Laravel disk this workspace lives on, and the path inside it.
            // Both are operator configuration: nothing an agent says reaches
            // either, because the root is the thing containment is measured
            // against.
            $table->string('disk', 64);
            $table->string('root_path', 500);

            // Null means unlimited, which is a decision an operator has to make
            // explicitly rather than the default falling open.
            $table->unsignedBigInteger('quota_bytes')->nullable();
            $table->unsignedBigInteger('used_bytes')->default(0);

            // Empty means every type is allowed. Non-empty is an allowlist,
            // matched on the DETECTED type, never the claimed extension.
            $table->json('allowed_mime_types')->nullable();

            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug'], 'pandora_workspaces_slug_uq');
        });

        $this->schema()->table($this->table('agents'), function (Blueprint $table): void {
            // The workspace an agent may reach, if any. Nullable, and null is
            // the default: an agent with no workspace can touch no files at
            // all, which is the right thing for an agent nobody has thought
            // about yet.
            $table->char('workspace_id', 26)->nullable()->after('approval_policy');
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('agents'), function (Blueprint $table): void {
            $table->dropColumn('workspace_id');
        });

        $this->schema()->dropIfExists($this->table('workspaces'));
    }
};
