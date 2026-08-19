<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Pandora\Approvals\Approval;
use Pandora\Approvals\ApprovalManager;
use Pandora\Exceptions\ApprovalNotPending;
use Pandora\Jobs\ExecuteToolCall;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\ToolExecution;

/**
 * Phase 9, criterion 17 — threat T14, the half `ApprovalRaceTest` cannot see.
 *
 * Two row locks carry the "exactly once" in T14, and neither was tested.
 * `ApprovalManager::resolve()` reads the approval with `lockForUpdate()`, and
 * `ExecuteToolCall::fanIn()` reads the run the same way. Both docblocks say
 * why: the check and the write "cannot be separated, which is what makes
 * double resolution impossible rather than merely unlikely", and "two tool
 * jobs finishing at the same instant cannot both read 'none left' and both
 * dispatch."
 *
 * The suite did not test either sentence. `ApprovalRaceTest`'s "two approvers
 * race" calls `approve()` twice in a row on one connection, which proves the
 * `isPending()` status check and nothing else — a check-then-write with no
 * lock at all passes it identically. Deleting either `lockForUpdate()` left
 * all 1,809 tests green (2026-08-19, verified one at a time). Two approvers
 * pressing the button at the same moment, and two tool jobs finishing in the
 * same instant, are precisely the cases a sequential test cannot reach.
 *
 * So this file uses a **second connection** to the same database and real row
 * contention, which means it needs an engine that has row locks. On SQLite
 * `lockForUpdate()` compiles to nothing at all — SQLite has no row locking,
 * and what stands in for it there is the database-wide write lock a
 * transaction takes, a different mitigation with a different proof. Rather
 * than pass vacuously on the leg where the control does not exist, this file
 * SKIPS there and runs on the four server-engine legs of the matrix.
 *
 * **How the two outcomes are told apart.** A rival connection holds a lock on
 * the approval's row; the manager is then asked to resolve an approval that is
 * *already resolved*. That probe, rather than a natural scenario, is what makes
 * the result unambiguous:
 *
 *   - lock present — the manager's read blocks on the rival and times out, so
 *     a `QueryException` comes back. It never learns the status.
 *   - lock absent — the read is an ordinary non-locking one, sails past the
 *     rival, sees `approved` and throws `ApprovalNotPending`.
 *
 * Asserting only "it throws" would pass either way: without the lock the
 * manager still blocks a moment later, on the UPDATE. It is *which* exception
 * arrives that says whether the decisive read was itself locked.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        test()->markTestSkipped(
            'Row locks need a server engine; SQLite compiles lockForUpdate() away. The matrix runs these legs.',
        );
    }

    RefundOrderTool::$refunds = [];
    $this->registerTools([RefundOrderTool::class]);
    $this->agentAllows(['refund_order']);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234',
            'amount_minor' => 4200,
        ])])
        ->willRespondWith('Done.');

    $this->pausedRun = $this->runToolAgent('Refund order ORD-1234.');
    $this->approval = Approval::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    // A second connection to the same database, so the contention is real
    // rather than simulated. Laravel hands back the same PDO for a repeated
    // connection name, which would make the "rival" the same session and lock
    // nothing, so it is registered under a name of its own.
    config(['database.connections.rival' => config('database.connections.testing')]);
    DB::purge('rival');

    $this->rival = DB::connection('rival');

    // Both sides fail fast instead of hanging the suite for the engine's
    // default (50 seconds on MySQL, forever on PostgreSQL).
    lockTimeoutSeconds(DB::connection(), 1);
    lockTimeoutSeconds($this->rival, 1);
});

afterEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        return;
    }

    // A rival transaction still open would deadlock the harness's own
    // between-test cleanup, which deletes from every table on the main
    // connection.
    while ($this->rival->transactionLevel() > 0) {
        $this->rival->rollBack();
    }

    DB::purge('rival');
});

/**
 * Bound how long a statement waits for a row lock before giving up.
 */
function lockTimeoutSeconds(ConnectionInterface $connection, int $seconds): void
{
    /** @var Connection $connection */
    match ($connection->getDriverName()) {
        'pgsql' => $connection->statement("SET lock_timeout = '".($seconds * 1000)."ms'"),
        default => $connection->statement('SET innodb_lock_wait_timeout = '.$seconds),
    };
}

/**
 * Hold a row lock on one row, on the rival connection, until rollback.
 */
function holdRowLock(Connection $rival, string $id, ?string $table = null): void
{
    $rival->beginTransaction();
    $rival->select(
        'select * from '.($table ?? (new Approval)->getTable()).' where id = ? for update',
        [$id],
    );
}

it('reads the approval under a row lock, so a concurrent resolver is held off the row', function (): void {
    // Already resolved. The manager would say so instantly on an unlocked
    // read, which is what makes the lock's presence visible.
    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    holdRowLock($this->rival, (string) $this->approval->getKey());

    // The rival is inside `resolve()`'s transaction, holding the row. A second
    // resolver must not get as far as reading the status.
    expect(fn () => app(ApprovalManager::class)->approve($this->approval->fresh(), null, authorize: false))
        ->toThrow(QueryException::class);
});

it('locks the approval that is being resolved and not the table', function (): void {
    // The control for the test above. A lock taken too broadly — or any
    // table-wide contention the engine happened to produce — would block here
    // too, and the test above would pass for a reason that has nothing to do
    // with this approval's row.
    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    /** @var Approval $unrelated */
    $unrelated = $this->approval->replicate();
    $unrelated->save();

    holdRowLock($this->rival, (string) $unrelated->getKey());

    // Not a lock timeout: the read gets through, finds the status, and reports
    // it — the ordinary already-resolved answer.
    expect(fn () => app(ApprovalManager::class)->approve($this->approval->fresh(), null, authorize: false))
        ->toThrow(ApprovalNotPending::class);
});

it('resolves normally once the rival releases the row', function (): void {
    // Proves the timeout above was contention rather than a manager that
    // cannot resolve anything on this connection at all.
    holdRowLock($this->rival, (string) $this->approval->getKey());

    expect(fn () => app(ApprovalManager::class)->approve($this->approval, null, authorize: false))
        ->toThrow(QueryException::class);

    $this->rival->rollBack();

    $resolved = app(ApprovalManager::class)->approve($this->approval->fresh(), null, authorize: false);

    expect($resolved->status->value)->toBe('approved')
        ->and(RefundOrderTool::$refunds)->toHaveCount(1);
});

/**
 * The fan-in counts outstanding calls under a lock on the RUN row, so that two
 * tool jobs finishing together cannot both read "none left" and both dispatch
 * the next continuation — which would run the model twice on one turn.
 *
 * Reached here through the redelivery path on purpose. A duplicate delivery
 * finds a terminal execution and goes straight to `fanIn()` without writing
 * anything else, so the rival's lock can only be contended by the fan-in's own
 * read. Driving a fresh call instead would insert steps and messages that
 * carry a foreign key to the same run row, and those inserts take a shared
 * lock on it — the test would then block whether or not `fanIn()` locked
 * anything, which is the failure mode this whole file exists to avoid.
 */
it('counts outstanding tool calls under a lock on the run row', function (): void {
    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    expect($execution->fresh()->isTerminal())->toBeTrue();

    holdRowLock($this->rival, (string) $this->pausedRun->getKey(), (new Run)->getTable());

    // Without the lock the read is an ordinary one: it slides past the rival,
    // finds the run terminal and returns quietly. With it, the read is the
    // thing that contends.
    expect(fn () => ExecuteToolCall::dispatchSync(
        (string) $execution->getKey(),
        $this->pausedRun->tenant_id,
        true,
        $this->pausedRun->actor_type,
        $this->pausedRun->actor_id,
    ))->toThrow(QueryException::class);
});

it('fans in normally once the rival releases the run row', function (): void {
    app(ApprovalManager::class)->approve($this->approval, null, authorize: false);

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();

    holdRowLock($this->rival, (string) $this->pausedRun->getKey(), (new Run)->getTable());
    $this->rival->rollBack();

    ExecuteToolCall::dispatchSync(
        (string) $execution->getKey(),
        $this->pausedRun->tenant_id,
        true,
        $this->pausedRun->actor_type,
        $this->pausedRun->actor_id,
    );

    // The redelivery is inert: one refund, as the run already recorded.
    expect(RefundOrderTool::$refunds)->toHaveCount(1);
});
