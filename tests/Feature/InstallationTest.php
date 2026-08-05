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
        'tool_executions', 'approvals',
        'provider_credentials', 'models', 'provider_health', 'usage_records',
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
        ->toContain('pandora:agent:run')
        ->toContain('pandora:tool:list')
        ->toContain('pandora:provider:test')
        ->toContain('pandora:model:sync')
        ->toContain('pandora:flush');
});

it('publishes the migrations an existing installation is missing', function (): void {
    // The failure this prevents: a package upgrade adds a table, the host has
    // published migrations already, and the installer skips the whole step
    // because "some are present". The first symptom is a missing-table error
    // in a page nobody associates with a package update.
    $directory = database_path('migrations');
    File::ensureDirectoryExists($directory);

    $packaged = collect(File::glob(dirname(__DIR__, 2).'/database/migrations/*.php'));

    // Pretend an older Pandora was installed: the first two tables only.
    $preexisting = $packaged->take(2)->map(function (string $path) use ($directory): string {
        $destination = $directory.'/'.basename($path);
        File::copy($path, $destination);

        return $destination;
    });

    $written = [];

    try {
        $this->artisan('pandora:install', ['--no-migrate' => true])->assertSuccessful();

        foreach ($packaged as $path) {
            $destination = $directory.'/'.basename($path);
            $written[] = $destination;

            expect(File::exists($destination))->toBeTrue('missing '.basename($path));
        }
    } finally {
        foreach (array_unique([...$written, ...$preexisting->all()]) as $path) {
            File::delete($path);
        }
    }
});

it('leaves a migration the host has already customised alone', function (): void {
    $directory = database_path('migrations');
    File::ensureDirectoryExists($directory);

    $packaged = (string) collect(File::glob(dirname(__DIR__, 2).'/database/migrations/*.php'))->first();
    $destination = $directory.'/'.basename($packaged);

    File::put($destination, '<?php // edited by the host');

    try {
        $this->artisan('pandora:install', ['--no-migrate' => true])->assertSuccessful();

        // Without --force, an installer that overwrote this would discard a
        // schema change the application depends on.
        expect(File::get($destination))->toBe('<?php // edited by the host');
    } finally {
        foreach (File::glob($directory.'/*_create_pandora_*_table.php') as $path) {
            File::delete($path);
        }
    }
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
