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
        $this->schema()->create($this->table('conversation_participants'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->char('conversation_id', 26);

            $table->string('participant_type');
            $table->string('participant_id');
            $table->string('role')->default('member');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['conversation_id', 'participant_type', 'participant_id'],
                'pandora_participants_unq',
            );
            $table->index(['participant_type', 'participant_id'], 'pandora_participants_lookup_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('conversation_participants'));
    }
};
