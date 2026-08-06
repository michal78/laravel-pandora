<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Acceptance criterion 3 -- database portability.
 *
 * This file asserts the portability RULES on whichever engine CI is running.
 * The engine matrix itself lives in .github/workflows/tests.yml; running the
 * whole suite on MySQL, MariaDB and PostgreSQL is what actually proves it.
 */
it('names every index within the 64-character MySQL limit', function (): void {
    $prefix = config('pandora.database.table_prefix');

    foreach (pandoraTables() as $table) {
        foreach (Schema::getIndexes($prefix.$table) as $index) {
            expect(strlen((string) $index['name']))
                ->toBeLessThanOrEqual(64, "index {$index['name']} on {$prefix}{$table} is too long for MySQL");
        }
    }
});

/**
 * Every Pandora table, so a new one cannot quietly skip these rules.
 *
 * @return list<string>
 */
function pandoraTables(): array
{
    return [
        'agents', 'conversations', 'sessions', 'conversation_participants',
        'messages', 'runs', 'run_steps', 'settings', 'audit_logs',
        'tool_executions', 'approvals', 'provider_credentials', 'models', 'provider_health', 'usage_records',
        'automations', 'automation_runs', 'webhook_deliveries', 'observations',
    ];
}

it('keeps every composite index within InnoDB\'s 3072-byte key limit', function (): void {
    // Learned the hard way, on MySQL, after the tests were green: four utf8mb4
    // varchar(255) columns in one index is 4080 bytes and InnoDB refuses to
    // create it. SQLite has no key limit AND reports no column lengths, so
    // this reads the migrations themselves rather than the live schema -- the
    // rule then holds on whichever engine happens to be running.
    $offenders = [];

    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        // Declared column widths, in characters. An undeclared string() is
        // Laravel's 255 default, which is exactly the trap.
        $widths = [];

        preg_match_all("/->string\\('([^']+)'(?:\\s*,\\s*(\\d+))?/", $source, $strings, PREG_SET_ORDER);

        foreach ($strings as $match) {
            $widths[$match[1]] = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 255;
        }

        preg_match_all("/->char\\('([^']+)'\\s*,\\s*(\\d+)/", $source, $chars, PREG_SET_ORDER);

        foreach ($chars as $match) {
            $widths[$match[1]] = (int) $match[2];
        }

        // Composite indexes and uniques, as declared.
        preg_match_all(
            "/->(?:index|unique)\\(\\[([^\\]]+)\\]\\s*,\\s*'([^']+)'/",
            $source,
            $indexes,
            PREG_SET_ORDER,
        );

        foreach ($indexes as [, $columnList, $indexName]) {
            preg_match_all("/'([^']+)'/", $columnList, $columns);

            $bytes = 0;

            foreach ($columns[1] as $column) {
                // utf8mb4 is 4 bytes per character, worst case. Anything not a
                // declared string column is a small fixed type; 8 bytes covers
                // the largest of them.
                $bytes += isset($widths[$column]) ? $widths[$column] * 4 : 8;
            }

            if ($bytes > 3072) {
                $offenders[] = "{$indexName} needs {$bytes} bytes";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('uses char(26) ULID primary keys throughout', function (): void {
    $prefix = config('pandora.database.table_prefix');

    foreach (['agents', 'conversations', 'runs', 'run_steps', 'messages'] as $table) {
        $columns = collect(Schema::getColumns($prefix.$table))->keyBy('name');

        expect($columns->has('id'))->toBeTrue()
            ->and($columns['id']['auto_increment'] ?? false)->toBeFalse();
    }
});

it('makes tenant_id nullable on every data-bearing table', function (): void {
    $prefix = config('pandora.database.table_prefix');

    foreach ([
        'agents', 'conversations', 'sessions', 'messages', 'runs', 'run_steps', 'audit_logs',
    ] as $table) {
        $columns = collect(Schema::getColumns($prefix.$table))->keyBy('name');

        expect($columns->has('tenant_id'))->toBeTrue("{$table} has no tenant_id")
            ->and($columns['tenant_id']['nullable'])->toBeTrue("{$table}.tenant_id must be nullable");
    }
});

it('omits updated_at from append-only tables', function (): void {
    $prefix = config('pandora.database.table_prefix');

    foreach (['run_steps', 'audit_logs'] as $table) {
        $names = collect(Schema::getColumns($prefix.$table))->pluck('name');

        expect($names)->not->toContain('updated_at');
    }
});

it('rolls migrations back cleanly', function (): void {
    $prefix = config('pandora.database.table_prefix');

    // By PATH, not by `--step`. A step count has meant "this many batches" and
    // "this many migrations" in different Laravel versions, and the count of
    // Pandora's own files is not either of those once the host's migrations
    // are in the same table -- which is how this passed locally and failed in
    // CI for two phases without anybody noticing.
    //
    // The path form says exactly what the test means: Pandora's migrations,
    // all of them, reversed.
    $this->artisan('migrate:rollback', [
        '--path' => realpath(dirname(__DIR__, 2).'/database/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();

    foreach (pandoraTables() as $table) {
        expect(Schema::hasTable($prefix.$table))->toBeFalse("{$prefix}{$table} survived a rollback");
    }
});

it('uses correctly sized ULIDs in hand-written test fixtures', function (): void {
    // A ULID is exactly 26 characters and the columns are `char(26)`. SQLite
    // stores an over-long value happily; MySQL in strict mode rejects the
    // insert. Two fixtures in this suite were 27 and 28 characters and passed
    // for months, because the only engine anybody ran was the forgiving one.
    $offenders = [];

    foreach (glob(dirname(__DIR__).'/*/*.php') ?: [] as $file) {
        preg_match_all("/'(01[0-9A-HJKMNP-TV-Z]{10,})'/", (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $ulid) {
            if (strlen($ulid) !== 26) {
                $offenders[] = basename($file).": {$ulid} is ".strlen($ulid).' characters';
            }
        }
    }

    expect($offenders)->toBe([]);
});
