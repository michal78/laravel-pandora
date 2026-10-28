<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

return new class extends Migration
{
    use ResolvesPandoraSchema;

    /**
     * Which agents this agent may delegate to.
     *
     * Shaped like `tool_policy` and read the same way, because it is the same
     * question: an agent reachable by omission is an agent nobody chose to
     * expose. NULL and `[]` both mean "delegates to nothing", which is the
     * default and the only safe one -- "any enabled agent" would make the whole
     * roster a graph where one weak node is every node.
     */
    public function up(): void
    {
        $this->schema()->table($this->table('agents'), function (Blueprint $table): void {
            $table->json('delegation_policy')->nullable()->after('approval_policy');
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('agents'), function (Blueprint $table): void {
            $table->dropColumn('delegation_policy');
        });
    }
};
