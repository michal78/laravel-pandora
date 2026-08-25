<?php

/**
 * A concurrent worker subprocess.
 *
 * Boots a Testbench application matching `TestCase`'s, joins the barrier, runs
 * one scenario and prints a single JSON line on stdout. Everything else it
 * emits is diagnostic and goes to stderr, so the parent can always parse the
 * last line.
 *
 * Invoked as: php worker.php <barrier-dir> <worker-index> <scenario-class> <payload-json>
 *
 * This file is deliberately a script rather than an artisan command. An
 * artisan command would need the package installed into a host application,
 * and the whole point is to boot the same skeleton the suite boots.
 */

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Pandora\PandoraServiceProvider;
use Pandora\Tests\Support\Concurrency\Barrier;
use Pandora\Tests\Support\Concurrency\Scenario;
use Pandora\Tests\Support\Concurrency\TestDatabase;

require __DIR__.'/../../../vendor/autoload.php';

/**
 * Anything at all going wrong has to come back as JSON, or the parent sees an
 * empty result and reports "the worker returned nothing" for what was actually
 * a fatal error with a perfectly good message.
 */
function fail(string $stage, Throwable $e): never
{
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'stage' => $stage,
        'error' => $e->getMessage(),
        'class' => $e::class,
        'file' => $e->getFile().':'.$e->getLine(),
    ], JSON_THROW_ON_ERROR)."\n");

    exit(1);
}

[$barrierDir, $index, $scenarioClass, $payloadJson] = [
    $argv[1] ?? '',
    (int) ($argv[2] ?? 0),
    $argv[3] ?? '',
    $argv[4] ?? '{}',
];

try {
    /** @var array<string, mixed> $payload */
    $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

    // `Application::create()` bootstraps as it builds, and its resolving
    // callback fires before `config` is bound -- so configuration goes on
    // afterwards. Every connection Pandora opens is lazy, so setting the
    // database here and purging is enough; nothing has connected yet.
    $app = Application::create(
        basePath: \Orchestra\Testbench\default_skeleton_path(),
        options: ['extra' => ['providers' => [PandoraServiceProvider::class]]],
    );

    $config = $app->make('config');

    $config->set('database.default', 'testing');
    $config->set('database.connections.testing', TestDatabase::connectionConfig());

    // Matches TestCase. A worker that reached a paid provider would be a bill
    // rather than a test failure.
    $config->set('pandora.providers.default', 'fake');
    $config->set('pandora.models.default', 'fake-model');

    // Nothing here has a browser to stream to, and a worker blocking on a
    // broadcast connection would look exactly like lock contention.
    $config->set('broadcasting.default', 'null');
    $config->set('pandora.realtime.enabled', false);
    $config->set('cache.default', 'array');
    $config->set('queue.default', 'sync');

    $app->make('db')->purge('testing');
} catch (Throwable $e) {
    fail('boot', $e);
}

try {
    if (! is_a($scenarioClass, Scenario::class, allow_string: true)) {
        throw new InvalidArgumentException("{$scenarioClass} is not a ".Scenario::class);
    }

    /** @var Scenario $scenario */
    $scenario = $app->make($scenarioClass);
} catch (Throwable $e) {
    fail('resolve', $e);
}

try {
    // Booting costs far more than the scenario does, and it varies per process.
    // Without a barrier here the first worker would routinely finish before the
    // last one had connected, and a test of contention would contend with
    // nothing while passing perfectly.
    (new Barrier($barrierDir))->arriveAndWait($index);
} catch (Throwable $e) {
    fail('barrier', $e);
}

try {
    $result = $scenario->run($payload);
} catch (Throwable $e) {
    fail('run', $e);
}

fwrite(STDOUT, json_encode([
    'ok' => true,
    'index' => $index,
    'result' => $result,
], JSON_THROW_ON_ERROR)."\n");

exit(0);
