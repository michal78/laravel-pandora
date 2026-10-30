<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

/**
 * RUNTIME settings only -- values an operator may need to change at 2am
 * without a deploy. Deployment configuration stays in config/pandora.php.
 * See docs/adr/0010-config-vs-database-settings.md.
 */
return new class extends Migration
{
    use ResolvesPandoraSchema;

    public function up(): void
    {
        $this->schema()->create($this->table('settings'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();

            $table->string('key');
            $table->json('value')->nullable();

            $table->string('updated_by_type')->nullable();
            $table->string('updated_by_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key'], 'pandora_settings_key_unq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('settings'));
    }
};
