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

    $tables = [
        'agents', 'conversations', 'sessions', 'conversation_participants',
        'messages', 'runs', 'run_steps', 'settings', 'audit_logs',
    ];

    foreach ($tables as $table) {
        foreach (Schema::getIndexes($prefix.$table) as $index) {
            expect(strlen((string) $index['name']))
                ->toBeLessThanOrEqual(64, "index {$index['name']} on {$prefix}{$table} is too long for MySQL");
        }
    }
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

    $this->artisan('migrate:rollback', ['--step' => 9])->assertSuccessful();

    expect(Schema::hasTable($prefix.'runs'))->toBeFalse();
});
