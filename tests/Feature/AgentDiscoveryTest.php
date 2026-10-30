<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

use function Orchestra\Testbench\default_migration_path;

use Pandora\Agents\AgentRegistry;
use Pandora\Tests\Fixtures\Discovery\Agents\Nested\BillingAgent;
use Pandora\Tests\Fixtures\Discovery\Agents\TriageAgent;
use Pandora\Tests\Fixtures\EchoAgent;
use Pandora\Tests\TestCase;

/**
 * `pandora.agents.discovery` -- published since v0.1.0 and, until v1.0, read
 * by nothing: an operator who enabled it got no agent, no error and no log
 * line (docs/development/open-questions.md). It now does what it says, and is
 * still off by default.
 */
function bootWith(array $config): void
{
    TestCase::$bootConfig = $config;

    test()->refreshApplication();

    // A rebuilt application on SQLite `:memory:` is a new, empty database, and
    // the harness rolls Pandora's migrations back when it tears down.
    if (config('database.connections.'.config('database.default').'.database') === ':memory:') {
        foreach ([default_migration_path(), dirname(__DIR__, 2).'/database/migrations'] as $path) {
            Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]);
        }
    }
}

/** @return list<string> */
function definedAgents(): array
{
    return array_values(app(AgentRegistry::class)->definitions());
}

const DISCOVERY_PATH = __DIR__.'/../Fixtures/Discovery/Agents';

it('registers every concrete agent definition under the path, recursively', function (): void {
    bootWith([
        'pandora.agents.discovery.enabled' => true,
        'pandora.agents.discovery.path' => DISCOVERY_PATH,
    ]);

    expect(definedAgents())->toEqualCanonicalizing([TriageAgent::class, BillingAgent::class]);
});

it('discovers nothing while disabled, which is the default', function (): void {
    expect(config('pandora.agents.discovery.enabled'))->toBeFalse();

    bootWith(['pandora.agents.discovery.path' => DISCOVERY_PATH]);

    expect(definedAgents())->toBe([]);
});

it('adds to the explicit definitions rather than replacing them', function (): void {
    bootWith([
        'pandora.agents.definitions' => [EchoAgent::class],
        'pandora.agents.discovery.enabled' => true,
        'pandora.agents.discovery.path' => DISCOVERY_PATH,
    ]);

    expect(definedAgents())->toEqualCanonicalizing([EchoAgent::class, TriageAgent::class, BillingAgent::class]);
});

it('tolerates a path that does not exist', function (): void {
    bootWith([
        'pandora.agents.discovery.enabled' => true,
        'pandora.agents.discovery.path' => DISCOVERY_PATH.'/missing',
    ]);

    expect(definedAgents())->toBe([]);
});
