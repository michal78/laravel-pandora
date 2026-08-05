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
        $this->schema()->create($this->table('messages'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->char('conversation_id', 26);
            $table->char('session_id', 26)->nullable();
            $table->char('run_id', 26)->nullable();

            $table->string('role');
            $table->string('type')->default('text');
            $table->unsignedInteger('sequence');

            $table->longText('content')->nullable();
            $table->string('content_format')->default('markdown');
            $table->json('structured')->nullable();
            $table->json('attachments')->nullable();

            $table->string('tool_call_id')->nullable();
            $table->json('usage')->nullable();

            // Persisted so a mid-stream reload renders the partial message and
            // knows more is coming.
            $table->string('streaming_state')->default('complete');

            $table->string('author_type')->nullable();
            $table->string('author_id')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['conversation_id', 'sequence'], 'pandora_messages_seq_unq');
            $table->index(['tenant_id', 'conversation_id', 'id'], 'pandora_messages_tenant_conv_idx');
            $table->index(['run_id'], 'pandora_messages_run_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('messages'));
    }
};
