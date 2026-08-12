<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRunner;
use Pandora\Contracts\ActorResolver;
use Pandora\Contracts\TenantResolver;
use Pandora\Pandora;
use Pandora\Providers\ProviderManager;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;

/**
 * Every Pandora migration this application has published, whatever timestamp it
 * carries. Matched on the suffix because the prefix is rewritten at publish
 * time when the application asks for that, and because not every migration is
 * a `create_..._table` -- the ones that alter a table are just as capable of
 * being re-run against a schema that already has them.
 *
 * @return list<string>
 */
function publishedPandoraMigrations(): array
{
    $suffix = static fn (string $path): string => (string) preg_replace('/^\d[\d_]*_/', '', basename($path));

    $packaged = collect(File::glob(dirname(__DIR__, 2).'/database/migrations/*.php'))
        ->map($suffix)
        ->all();

    return collect(File::glob(database_path('migrations/*.php')))
        ->filter(static fn (string $path): bool => in_array($suffix($path), $packaged, true))
        ->values()
        ->all();
}

function forgetPublishedPandoraMigrations(): void
{
    foreach (publishedPandoraMigrations() as $path) {
        File::delete($path);
    }
}

/**
 * `pandora:install` publishes the CONFIG as well as the migrations, into the
 * Testbench skeleton this suite boots from — and a published config shadows
 * the package's own, because `mergeConfigFrom()` merges one level deep and its
 * top-level arrays replace ours outright.
 *
 * Nothing fails when that happens. The next run of the suite simply exercises
 * a snapshot of whatever the config looked like when it was published, so a
 * key added to the package silently does not exist and a default changed in
 * the package is silently not the one under test. It has been found and
 * deleted by hand four times, twice while it was actively hiding a defect, and
 * the cause was never chased because nothing pointed at this file.
 *
 * This is the guard. It runs after every test here, because this is the only
 * file that installs.
 */
afterEach(function (): void {
    File::delete(config_path('pandora.php'));
    forgetPublishedPandoraMigrations();
});

/** Acceptance criteria 1, 2, 3 -- installation. */
it('boots with no configuration at all', function (): void {
    expect(app()->bound(Pandora::class))->toBeTrue()
        ->and(app(TenantResolver::class))->toBeInstanceOf(TenantResolver::class)
        ->and(app(ActorResolver::class))->toBeInstanceOf(ActorResolver::class)
        ->and(app(AgentRunner::class))->toBeInstanceOf(AgentRunner::class)
        ->and(app(ProviderManager::class))->toBeInstanceOf(ProviderManager::class);
});

it('exposes the facade', function (): void {
    expect(\Pandora\Facades\Pandora::version())->toBeString();
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

        // Matched on the suffix, not the whole filename: what is published
        // carries the timestamp of the moment it was published when the
        // application asks for that, so only the part naming the table is
        // stable.
        $suffix = static fn (string $path): string => (string) preg_replace('/^\d[\d_]*_/', '', basename($path));

        $written = publishedPandoraMigrations();

        $found = collect($written)->map($suffix)->all();

        foreach ($packaged as $path) {
            expect($found)->toContain($suffix($path));
        }
    } finally {
        forgetPublishedPandoraMigrations();

        foreach ($preexisting as $path) {
            File::delete($path);
        }
    }
});

/**
 * Also from the clean-install proof: the packaged migrations are named
 * `0001_01_01_*` so they sort among themselves, and publishing those names
 * verbatim left the host no way to order its own migrations relative to
 * Pandora's -- everything it wrote would run afterwards, whatever it called it.
 * Laravel also reported a negative duration for a migration dated year 1.
 */
it('publishes migrations under a current timestamp, not the packaged 0001 prefix', function (): void {
    $directory = database_path('migrations');
    File::ensureDirectoryExists($directory);

    // The application's own switch, read by `vendor:publish` too. Pandora
    // follows it rather than inventing a second answer to the same question.
    config(['database.migrations.update_date_on_publish' => true]);

    try {
        $this->artisan('pandora:install', ['--no-migrate' => true])->assertSuccessful();

        $published = collect(File::glob($directory.'/*_create_pandora_*_table.php'))
            ->map(static fn (string $path): string => basename($path));

        expect($published)->not->toBeEmpty();

        foreach ($published as $name) {
            expect($name)->not->toStartWith('0001_01_01_')
                ->and($name)->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_pandora_/');
        }

        // Sorted the same way they are packaged, or the tables arrive in an
        // order their foreign keys do not survive.
        expect($published->sort()->values()->all())->toBe($published->values()->all());
    } finally {
        forgetPublishedPandoraMigrations();
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

/**
 * Found by a clean-install proof: `pandora:install --no-interaction` printed
 * "migrations not run (non-interactive)" and then "Pandora is installed", and
 * exited 0 with no schema. A scripted install had no error to detect, and the
 * first symptom was a missing table in whatever page somebody opened first.
 *
 * `--no-interaction` means "take the default answers", and the default answer
 * to "run the migrations?" is yes. Opting out is what `--no-migrate` is for.
 */
it('runs the migrations when it is not interactive, rather than exiting 0 with no schema', function (): void {
    /** @var string $prefix */
    $prefix = config('pandora.database.table_prefix', 'pandora_');

    // The published names have to match the ones this test application already
    // ran, or `migrate` re-applies the package's own migrations against a
    // schema that has them. A host publishing for the first time has no such
    // history; the covered behaviour here is what the command DOES, not what
    // it names.
    config(['database.migrations.update_date_on_publish' => false]);

    forgetPublishedPandoraMigrations();

    try {
        // Through `Artisan::call`, which is how a deploy script runs it: no
        // TTY, no prompt, and an exit code as the only signal anybody gets.
        expect(Artisan::call('pandora:install'))->toBe(0)
            ->and(Artisan::output())->not->toContain('not run (non-interactive)')
            ->and(Schema::hasTable($prefix.'agents'))->toBeTrue();
    } finally {
        forgetPublishedPandoraMigrations();
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

it('does not call the control center enabled when it is switched off', function (): void {
    // The three-state report exists because two states were being collapsed
    // into one: `pandora.ui.enabled` says the control center is WANTED, and
    // `Livewire` says it can exist. A stock install without Livewire registers
    // no /pandora route at all, and this line used to call that "enabled" —
    // sending a first-time user to a 404 on the most visual thing we ship.
    config()->set('pandora.ui.enabled', false);

    $this->artisan('pandora:status')
        ->expectsOutputToContain('headless')
        ->assertSuccessful();
});

it('calls the control center enabled when it is on and Livewire is present', function (): void {
    config()->set('pandora.ui.enabled', true);

    // The third state — wanted but unavailable — cannot be asserted here.
    // `livewire/livewire` is a dev dependency of this package, so
    // `class_exists()` is true for the whole suite and the branch that reports
    // it missing is unreachable by construction. It is verified by hand against
    // a fresh application, and recorded in `docs/development/fake-boundaries.md`
    // rather than left to look covered.
    $this->artisan('pandora:status')
        ->expectsOutputToContain('enabled')
        ->assertSuccessful();
});

it('lists agents, and says so clearly when there are none', function (): void {
    $this->artisan('pandora:agent:list')->assertSuccessful();
});

/**
 * The Phase 3.5 walkthrough asks for the page's run counts to be cross-checked
 * against this command, which had no run count to check them against.
 */
it('lists how many runs each agent has, so the page can be cross-checked against it', function (): void {
    $agent = Agent::query()->create([
        'name' => 'Counted', 'slug' => 'counted', 'enabled' => true,
        'default_provider' => 'fake', 'default_model' => 'fake-model',
        'max_iterations' => 3, 'max_tool_calls' => 5,
        'max_duration_seconds' => 60, 'context_budget_tokens' => 4000,
    ]);

    foreach (range(1, 2) as $ignored) {
        Run::query()->create([
            'agent_id' => $agent->getKey(),
            'session_id' => (string) str()->ulid(),
            'correlation_id' => (string) str()->ulid(),
            'state' => RunState::Completed->value,
        ]);
    }

    $this->artisan('pandora:agent:list')
        ->expectsOutputToContain('Runs')
        ->expectsOutputToContain('counted')
        ->assertSuccessful();

    // The column, not merely the heading: the command must report 2 rather
    // than the 0 an unwired column would show.
    Artisan::call('pandora:agent:list');

    expect(Artisan::output())->toMatch('/counted.*\|\s*2\s*\|/s');
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
