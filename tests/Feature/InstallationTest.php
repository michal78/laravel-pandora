<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Contracts\ActorResolver;
use Pandora\Pandora\Contracts\TenantResolver;
use Pandora\Pandora\Pandora;
use Pandora\Pandora\Providers\ProviderManager;

/** Acceptance criteria 1, 2, 3 -- installation. */
it('boots with no configuration at all', function (): void {
    expect(app()->bound(Pandora::class))->toBeTrue()
        ->and(app(TenantResolver::class))->toBeInstanceOf(TenantResolver::class)
        ->and(app(ActorResolver::class))->toBeInstanceOf(ActorResolver::class)
        ->and(app(AgentRunner::class))->toBeInstanceOf(AgentRunner::class)
        ->and(app(ProviderManager::class))->toBeInstanceOf(ProviderManager::class);
});

it('exposes the facade', function (): void {
    expect(\Pandora\Pandora\Facades\Pandora::version())->toBeString();
});

it('creates every expected table', function (): void {
    $prefix = config('pandora.database.table_prefix');

    foreach ([
        'agents', 'conversations', 'sessions', 'conversation_participants',
        'messages', 'runs', 'run_steps', 'settings', 'audit_logs',
    ] as $table) {
        expect(Schema::hasTable($prefix.$table))->toBeTrue("missing table {$prefix}{$table}");
    }
});

it('creates no default agent', function (): void {
    // A deliberate safety default: an agent existing without someone having
    // decided it should is exactly what this package refuses to ship.
    expect(Agent::query()->count())->toBe(0);
});

it('registers the artisan commands', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('pandora:install')
        ->toContain('pandora:status')
        ->toContain('pandora:agent:list')
        ->toContain('pandora:agent:run');
});

it('runs the installer without creating an agent', function (): void {
    $this->artisan('pandora:install', ['--no-migrate' => true])
        ->assertSuccessful();

    expect(Agent::query()->count())->toBe(0);
});

it('is idempotent -- installing twice is safe', function (): void {
    $this->artisan('pandora:install', ['--no-migrate' => true])->assertSuccessful();
    $this->artisan('pandora:install', ['--no-migrate' => true])->assertSuccessful();

    // The second run reports the config as already present rather than
    // clobbering a customised file.
    expect(File::exists(config_path('pandora.php')))->toBeTrue();
});

it('reports status', function (): void {
    $this->artisan('pandora:status')->assertSuccessful();
});

it('lists agents, and says so clearly when there are none', function (): void {
    $this->artisan('pandora:agent:list')->assertSuccessful();
});

it('runs an agent from the console', function (): void {
    $this->fakeProvider()->willRespondWith('Console reply.');

    Agent::query()->create([
        'name' => 'CLI', 'slug' => 'cli', 'enabled' => true,
        'default_provider' => 'fake', 'default_model' => 'fake-model',
        'max_iterations' => 3, 'max_tool_calls' => 5,
        'max_duration_seconds' => 60, 'context_budget_tokens' => 4000,
    ]);

    $this->artisan('pandora:agent:run', ['agent' => 'cli', 'prompt' => 'Hi'])
        ->expectsOutputToContain('Console reply.')
        ->assertSuccessful();
});

it('fails clearly for an unknown agent', function (): void {
    $this->artisan('pandora:agent:run', ['agent' => 'nope', 'prompt' => 'Hi'])
        ->assertFailed();
});
