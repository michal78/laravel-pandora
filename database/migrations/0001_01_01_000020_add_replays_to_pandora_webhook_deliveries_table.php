<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

return new class extends Migration
{
    use ResolvesPandoraSchema;

    /**
     * Make a replayed delivery visible.
     *
     * Replay protection works by a unique `(automation_id, signature)` insert,
     * which means the second delivery cannot record itself -- the collision is
     * the whole mechanism. So it recorded nothing at all, and a 409 left no
     * trace anywhere: the one rejection with no evidence.
     *
     * Counting it on the row it duplicates keeps the claim as an insert and
     * still answers "did that retry arrive?". It is also the number worth
     * watching on its own: a sender whose retry logic is wrong shows up here
     * long before anybody notices it in a bill.
     */
    public function up(): void
    {
        $this->schema()->table($this->table('webhook_deliveries'), function (Blueprint $table): void {
            $table->unsignedInteger('replay_count')->default(0)->after('reason');
            $table->timestamp('last_replayed_at')->nullable()->after('replay_count');
        });
    }

    public function down(): void
    {
        $this->schema()->table($this->table('webhook_deliveries'), function (Blueprint $table): void {
            $table->dropColumn(['replay_count', 'last_replayed_at']);
        });
    }
};
