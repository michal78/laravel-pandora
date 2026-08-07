<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

return new class extends Migration
{
    use ResolvesPandoraSchema;

    /**
     * `parent_run_id` and `delegation_depth` have been on the row since Phase 1.
     * What was missing is the answer to "what was this child allowed to do, and
     * why" -- and that answer has to be a stored fact rather than a derivation.
     *
     * `effective_tools` is the ability INTERSECTION, computed once when the
     * delegation is authorized and frozen here. Recomputing it at each tool call
     * would let the two sides drift: an operator widening the child agent's
     * allowlist mid-run would retroactively widen a run the parent authorized
     * under narrower terms. Frozen, a trace can say what the child could do
     * without re-deriving a history that has since changed underneath it.
     *
     * NULL means "no intersection applies" -- an ordinary top-level run, whose
     * abilities are its agent's allowlist. It is emphatically not the same as
     * `[]`, which is a delegation that intersected down to nothing and may
     * therefore call no tools at all.
     *
     * `delegated_tool_execution_id` is the parent's tool-call row this child
     * answers. The parent parks on that row, and the child's terminal state
     * completes it -- so the link has to be navigable from the child, which is
     * the side that finishes first.
     */
    public function up(): void
    {
        $this->schema()->table($this->table('runs'), function (Blueprint $table): void {
            $table->json('effective_tools')->nullable()->after('delegation_depth');
            $table->char('delegated_tool_execution_id', 26)->nullable()->after('effective_tools');
        });

        $this->schema()->table($this->table('runs'), function (Blueprint $table): void {
            $table->index(['delegated_tool_execution_id'], 'pandora_runs_delegated_exec_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('runs'), function (Blueprint $table): void {
            $table->dropIndex('pandora_runs_delegated_exec_idx');
            $table->dropColumn(['effective_tools', 'delegated_tool_execution_id']);
        });
    }
};
