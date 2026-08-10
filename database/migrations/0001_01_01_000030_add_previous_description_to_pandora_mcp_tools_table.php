<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

return new class extends Migration
{
    use ResolvesPandoraSchema;

    /**
     * What the description said before the server rewrote it.
     *
     * `previous_schema_hash` records THAT something moved. It cannot record
     * what, and "what" is the entire question when the thing that moved is a
     * sentence written by a stranger and read by a model. An operator asked to
     * re-approve is being asked to read a diff; without this column there is
     * no diff to read, only two hashes that differ.
     *
     * Written only when the description actually changed, so a parameter-only
     * change leaves it null and the control center can say which of the two
     * happened.
     *
     * As untrusted as its successor, and escaped wherever it is rendered.
     */
    public function up(): void
    {
        $this->schema()->table($this->table('mcp_tools'), function (Blueprint $table): void {
            $table->text('previous_description')->nullable()->after('previous_schema_hash');
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('mcp_tools'), function (Blueprint $table): void {
            $table->dropColumn('previous_description');
        });
    }
};
