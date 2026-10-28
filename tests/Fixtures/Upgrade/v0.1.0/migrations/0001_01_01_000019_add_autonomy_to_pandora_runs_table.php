<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

return new class extends Migration
{
    use ResolvesPandoraSchema;

    /**
     * ADR-0009 requires every autonomous run to record its trigger, its policy
     * decision and its budget. The trigger has been on the row since Phase 1;
     * this adds the other two.
     *
     * `autonomy_level` is NULL for an interactive run and that is meaningful,
     * not missing data: a human is right there, watching, and the tool policy
     * and approvals are the boundary. A non-null value means nobody is, and
     * the gatekeeper clamps the run to it.
     */
    public function up(): void
    {
        $this->schema()->table($this->table('runs'), function (Blueprint $table): void {
            $table->string('autonomy_level', 32)->nullable()->after('trigger_id');
            $table->char('automation_id', 26)->nullable()->after('autonomy_level');
        });

        $this->schema()->table($this->table('runs'), function (Blueprint $table): void {
            $table->index(['automation_id', 'created_at'], 'pandora_runs_automation_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('runs'), function (Blueprint $table): void {
            $table->dropIndex('pandora_runs_automation_idx');
            $table->dropColumn(['autonomy_level', 'automation_id']);
        });
    }
};
