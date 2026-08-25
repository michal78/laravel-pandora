<?php

declare(strict_types=1);

namespace Pandora\Tests\Support\Concurrency;

/**
 * The connection the suite is testing, in one place.
 *
 * `TestCase` needs it and so does every concurrent worker subprocess, and the
 * two must not drift: a worker pointed at a different database than the test
 * that spawned it would contend with nothing and pass everything. Extracted
 * here rather than duplicated for exactly that reason.
 */
final class TestDatabase
{
    /**
     * @return array<string, mixed>
     */
    public static function connectionConfig(): array
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
     * Whether this leg can host a concurrent test at all.
     *
     * SQLite cannot, and not for a tunable reason: the suite runs `:memory:`,
     * where every connection is a private database, so two processes would
     * contend over nothing whatsoever and agree perfectly. A file-backed SQLite
     * would at least share bytes, but it serialises writers with a
     * database-wide lock and has no row locking for `lockForUpdate()` to
     * compile to -- a different mitigation needing a different proof.
     */
    public static function supportsConcurrency(): bool
    {
        $driver = env('DB_CONNECTION', 'sqlite');

        return $driver !== 'sqlite' && $driver !== 'testing';
    }

    /**
     * The environment a worker subprocess needs to reach the same database.
     *
     * @return array<string, string>
     */
    public static function workerEnvironment(): array
    {
        $keys = ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'APP_KEY'];
        $environment = [];

        foreach ($keys as $key) {
            $value = env($key);

            if ($value !== null && $value !== false) {
                $environment[$key] = (string) $value;
            }
        }

        return $environment;
    }
}
