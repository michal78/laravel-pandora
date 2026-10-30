<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

use function Orchestra\Testbench\default_migration_path;

/**
 * Phase 9, criterion 24 -- migrations run forward from a v0 install to head,
 * on whichever engine the matrix is running.
 *
 * Every other test in the suite starts from an empty database, so none of
 * them can see an upgrade bug: a migration that only works on a schema that
 * never existed, or an installer that cannot tell what a host already has.
 * This one starts from what a host that installed v0.1.0 actually has on
 * disk and in its database, and upgrades it the documented way.
 *
 * The v0.1.0 migrations are a frozen copy under tests/Fixtures/Upgrade,
 * taken from the tag, because CI checks out without tags and a test that
 * shells out to git is a test of the checkout.
 */
function v0Migrations(): string
{
    return dirname(__DIR__).'/Fixtures/Upgrade/v0.1.0/migrations';
}

function packagedMigrations(): string
{
    return dirname(__DIR__, 2).'/database/migrations';
}

function migrationSuffix(string $path): string
{
    return (string) preg_replace('/^\d[\d_]*_/', '', basename($path));
}

/**
 * Every Pandora table's columns and index names, in a form two schemas on the
 * same engine can be compared by.
 *
 * @return array<string, array{columns: array<string, string>, indexes: list<string>}>
 */
function pandoraSchemaShape(): array
{
    /** @var string $prefix */
    $prefix = config('pandora.database.table_prefix', 'pandora_');

    $shape = [];

    foreach (Schema::getTables() as $table) {
        $name = (string) $table['name'];

        if (! str_starts_with($name, $prefix)) {
            continue;
        }

        $columns = [];

        foreach (Schema::getColumns($name) as $column) {
            $columns[(string) $column['name']] = $column['type_name'].($column['nullable'] ? ' null' : '');
        }

        ksort($columns);

        $indexes = array_map(
            static fn (array $index): string => (string) $index['name'],
            Schema::getIndexes($name),
        );

        sort($indexes);

        $shape[$name] = ['columns' => $columns, 'indexes' => $indexes];
    }

    ksort($shape);

    return $shape;
}

/**
 * Publish v0.1.0's migrations the way a host did: into its own migrations
 * directory, under the timestamp of the day it installed.
 *
 * @return list<string>
 */
function publishV0(string $directory): array
{
    File::ensureDirectoryExists($directory);

    $published = [];

    foreach (collect(File::glob(v0Migrations().'/*.php'))->sort()->values() as $index => $path) {
        $name = sprintf('2026_08_12_%06d_%s', 100000 + $index, migrationSuffix($path));
        File::copy($path, $directory.'/'.$name);
        $published[] = $directory.'/'.$name;
    }

    return $published;
}

it('never edits a migration after it has shipped', function (): void {
    // The upgrade bug that needs no new migration to happen: a shipped file
    // edited in place. A host that ran the old version never runs it again,
    // so its schema silently differs from every fresh install. A change to a
    // shipped table is a NEW migration, always.
    foreach (File::glob(v0Migrations().'/*.php') as $shipped) {
        $packaged = packagedMigrations().'/'.basename($shipped);

        expect(File::exists($packaged))->toBeTrue(basename($shipped).' shipped in v0.1.0 and has been removed')
            ->and(File::get($packaged))->toBe(File::get($shipped), basename($shipped).' has been edited since it shipped in v0.1.0');
    }
});

it('upgrades a v0.1.0 install to head with its data intact', function (): void {
    // What a fresh install at head looks like on this engine -- the harness
    // has just built exactly that.
    $expected = pandoraSchemaShape();

    $directory = database_path('migrations');

    // The installer's restamping is what a current Laravel application asks
    // for, and it is the case where a published file can only be recognised
    // by what it migrates rather than by its whole name.
    config(['database.migrations.update_date_on_publish' => true]);

    $published = [];

    try {
        // 1. The host as it stood after installing v0.1.0.
        Artisan::call('db:wipe', ['--drop-views' => true, '--force' => true]);

        $published = publishV0($directory);

        expect(Artisan::call('migrate', ['--path' => $directory, '--realpath' => true, '--force' => true]))
            ->toBe(0);

        // 2. Something worth keeping, written while it was on v0.1.0. Raw
        //    inserts against the v0 columns, because the models describe head.
        /** @var string $prefix */
        $prefix = config('pandora.database.table_prefix', 'pandora_');
        $agentId = '01JBV0UPGRADE0000000AGENT1';

        DB::table($prefix.'agents')->insert([
            'id' => $agentId,
            'name' => 'Support',
            'slug' => 'support',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. The upgrade, as documented: the current installer, then migrate.
        //    Installing twice is what an upgrade IS from the installer's side,
        //    and it is where duplicate publishing used to break `migrate`.
        expect(Artisan::call('pandora:install', ['--no-migrate' => true]))->toBe(0)
            ->and(Artisan::call('migrate', ['--path' => $directory, '--realpath' => true, '--force' => true]))
            ->toBe(0);

        // Each packaged migration published once -- never a v0 file AND a
        // fresh copy of the same thing.
        $suffixes = collect(File::glob($directory.'/*.php'))->map(migrationSuffix(...));

        expect($suffixes->duplicates()->all())->toBe([])
            ->and($suffixes->sort()->values()->all())->toBe(
                collect(File::glob(packagedMigrations().'/*.php'))->map(migrationSuffix(...))->sort()->values()->all(),
            );

        // 4. The same schema a fresh install has, on this engine.
        expect(pandoraSchemaShape())->toBe($expected);

        // 5. And the row written on v0.1.0 is still there, still itself.
        expect(DB::table($prefix.'agents')->where('id', $agentId)->value('slug'))->toBe('support');
    } finally {
        foreach (File::glob($directory.'/*.php') as $path) {
            if (in_array(migrationSuffix($path), collect(File::glob(packagedMigrations().'/*.php'))->map(migrationSuffix(...))->all(), true)) {
                File::delete($path);
            }
        }

        File::delete(config_path('pandora.php'));

        // The migrations table now names published files rather than the
        // packaged ones, so a rollback by package path would find nothing to
        // reverse -- and the harness rolls back by package path when the
        // application is torn down. Rebuild exactly what the harness builds.
        Artisan::call('db:wipe', ['--drop-views' => true, '--force' => true]);

        foreach ([default_migration_path(), packagedMigrations()] as $path) {
            Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]);
        }
    }
});
