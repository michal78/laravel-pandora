<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;

use function Orchestra\Testbench\default_migration_path;

use Orchestra\Testbench\TestCase as Orchestra;
use Pandora\Pandora\PandoraServiceProvider;
use Pandora\Pandora\Providers\Adapters\FakeProvider;
use Pandora\Pandora\Providers\ProviderManager;
use Pandora\Pandora\Tests\Fixtures\TestUser;

abstract class TestCase extends Orchestra
{
    /**
     * Whether a server engine has already been migrated in this process.
     *
     * Irrelevant for SQLite `:memory:`, where every connection is a brand new
     * empty database and migrating per test costs milliseconds. On MySQL,
     * MariaDB or PostgreSQL the database persists across tests in the same
     * process, and re-running 25 migrations for each of ~900 tests turns a
     * forty-second suite into a forty-minute one -- which is the real reason
     * nobody noticed the matrix was not testing anything.
     */
    private static bool $serverSchemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase();

        // No test may reach a network. This is a hard guarantee rather than a
        // convention: any HTTP request without a matching fake now throws,
        // including one made by a code path nobody thought to fake. See
        // tests/Providers/NoLiveCallsTest.php.
        Http::preventStrayRequests();
    }

    /**
     * Give this test an empty schema, by whichever route is cheap.
     *
     * Truncation rather than a wrapping transaction on server engines. A
     * transaction would be faster, but Pandora's own code catches unique-key
     * violations as a normal control-flow outcome -- the automation occurrence
     * claim does exactly that -- and on PostgreSQL a failed statement poisons
     * the surrounding transaction. Tests would then fail for a reason that
     * exists only in the harness.
     */
    private function prepareDatabase(): void
    {
        // The host's own tables (users, jobs, cache) plus Pandora's. Pandora
        // never migrates a host table, but its tests need one to authorize
        // against -- proving the package works with an ordinary Laravel user.
        if (! $this->onServerEngine()) {
            $this->loadLaravelMigrations();
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            return;
        }

        if (self::$serverSchemaReady && $this->schemaStillExists()) {
            $this->truncateAllTables();

            return;
        }

        // Deliberately NOT testbench's `loadLaravelMigrations()` here. That
        // helper registers a rollback for when the application is destroyed,
        // which is exactly right for a throwaway `:memory:` database and
        // exactly wrong for a server one: the schema would be dropped after
        // every test and the "migrate once" saving would be imaginary. It also
        // took a while to notice, because the symptom is a truncate failing on
        // a table that existed a moment ago.
        $this->artisan('db:wipe', ['--drop-views' => true]);

        foreach ([default_migration_path(), __DIR__.'/../database/migrations'] as $path) {
            $this->artisan('migrate', ['--path' => $path, '--realpath' => true]);
        }

        self::$serverSchemaReady = true;
    }

    /**
     * Is the shared schema still there?
     *
     * A cached "yes, migrated" flag is not enough on a server engine, because
     * one test in this suite deliberately rolls every migration back --
     * `PortabilityTest` proves the migrations reverse cleanly, which it can
     * only do by reversing them. On a throwaway `:memory:` database that
     * harms nothing; on a shared one it deletes the schema out from under
     * every test that follows, and the symptom is dozens of unrelated
     * failures in whichever file happened to run next.
     *
     * One cheap existence check per test, and the harness heals itself.
     */
    private function schemaStillExists(): bool
    {
        /** @var string $prefix */
        $prefix = config('pandora.database.table_prefix', 'pandora_');

        return Schema::hasTable($prefix.'runs');
    }

    private function truncateAllTables(): void
    {
        $connection = DB::connection();
        $connection->statement($this->disableForeignKeys());

        foreach ($this->tablesInThisDatabase() as $table) {
            $connection->table($table)->truncate();
        }

        $connection->statement($this->enableForeignKeys());
    }

    /**
     * The tables belonging to THIS connection's database, and no others.
     *
     * `Schema::getTableListing()` lists every schema the credentials can see.
     * On a developer machine where the same MySQL server also holds unrelated
     * databases, that means truncating a table name that does not exist here
     * and failing -- and it is one small mistake away from truncating a table
     * that does exist somewhere it should not have been touched.
     *
     * @return list<string>
     */
    private function tablesInThisDatabase(): array
    {
        $database = DB::connection()->getDatabaseName();

        return array_values(array_filter(
            array_map(
                static fn (array $table): string => (string) $table['name'],
                array_filter(
                    Schema::getTables(),
                    static fn (array $table): bool => ($table['schema'] ?? null) === $database
                        || ($table['schema'] ?? null) === 'public',
                ),
            ),
            // The ledger of what has been migrated is the one table that must
            // survive, or the next test re-runs every migration.
            static fn (string $name): bool => $name !== 'migrations',
        ));
    }

    private function disableForeignKeys(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? 'SET session_replication_role = replica'
            : 'SET FOREIGN_KEY_CHECKS=0';
    }

    private function enableForeignKeys(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? 'SET session_replication_role = DEFAULT'
            : 'SET FOREIGN_KEY_CHECKS=1';
    }

    private function onServerEngine(): bool
    {
        return DB::connection()->getDriverName() !== 'sqlite';
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PandoraServiceProvider::class,
            LivewireServiceProvider::class,
        ];
    }

    /**
     * The engine this run is testing.
     *
     * SQLite in memory unless `DB_CONNECTION` names something else, which is
     * what the CI matrix sets. This used to hardcode SQLite unconditionally,
     * so the three matrix jobs connected to nothing, tested nothing, and went
     * green -- which is worse than not having them, because three passing jobs
     * look like coverage.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->connectionConfig());

        $app['config']->set('auth.providers.users.model', TestUser::class);

        // Tests never call a paid API.
        $app['config']->set('pandora.providers.default', 'fake');
        $app['config']->set('pandora.models.default', 'fake-model');
        $app['config']->set('pandora.realtime.enabled', true);
        $app['config']->set('pandora.ui.enabled', true);
        $app['config']->set('pandora.routes.middleware', ['web']);

        // Flush immediately in tests so assertions do not have to wait out a
        // coalescing window that exists for production message volume.
        $app['config']->set('pandora.realtime.stream.flush_chars', 1);
        $app['config']->set('pandora.realtime.stream.flush_interval_ms', 0);
    }

    /**
     * Deliberately NOT named `testingConnection`: Pint's Laravel preset
     * applies `php_unit_method_casing` to anything in a test class whose name
     * begins with "test", and silently renamed it to `testing_connection`
     * without touching the call site.
     *
     * @return array<string, mixed>
     */
    private function connectionConfig(): array
    {
        $driver = env('DB_CONNECTION', 'sqlite');

        if ($driver === 'sqlite' || $driver === 'testing') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            // `mariadb` is its own driver in Laravel 11+, and using `mysql`
            // for it hides exactly the differences the matrix exists to find.
            'driver' => $driver,
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', $driver === 'pgsql' ? '5432' : '3306'),
            'database' => env('DB_DATABASE', 'pandora'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            // The collation the InnoDB key-length rule is written against.
            // A narrower one would let an index that is too wide in production
            // pass here.
            'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => $driver === 'pgsql' ? 'public' : null,
            'sslmode' => $driver === 'pgsql' ? 'prefer' : null,
        ];
    }

    /**
     * The shared fake provider instance, so a test can script it and the run
     * under test resolves the same object.
     */
    protected function fakeProvider(): FakeProvider
    {
        /** @var FakeProvider $provider */
        $provider = $this->app->make(ProviderManager::class)->provider('fake');

        return $provider;
    }

    protected function actingAsUser(?TestUser $user = null): TestUser
    {
        $user ??= TestUser::create([
            'name' => 'Test User',
            'email' => 'user'.uniqid().'@example.test',
            'password' => 'secret',
        ]);

        $this->actingAs($user);

        return $user;
    }
}
