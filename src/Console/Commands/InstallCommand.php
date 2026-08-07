<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\confirm;

use Throwable;

/**
 * Idempotent installer.
 *
 * Safe to run repeatedly: existing files are never overwritten without
 * consent, and the command deliberately creates NO default agent. An agent
 * existing without someone having decided it should is exactly the kind of
 * unsafe default this project refuses to ship.
 */
final class InstallCommand extends Command
{
    protected $signature = 'pandora:install
                            {--force : Overwrite existing published files}
                            {--no-migrate : Skip running migrations}';

    protected $description = 'Install Pandora: publish configuration and migrations, and explain the remaining setup.';

    public function handle(): int
    {
        $this->components->info('Installing Pandora');

        $this->publishConfig();
        $this->publishMigrations();
        $migrated = $this->runMigrations();
        $this->explainSetup();

        $this->newLine();

        if ($migrated && ! $this->schemaIsInstalled()) {
            $this->components->error('Pandora is NOT installed: the migrations ran but the schema is missing.');
            $this->line('  Check the database connection, then run `php artisan migrate` and `php artisan pandora:status`.');

            return self::FAILURE;
        }

        $this->components->info('Pandora is installed.');

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        if (File::exists(config_path('pandora.php')) && ! $this->option('force')) {
            $this->components->twoColumnDetail('config/pandora.php', '<fg=yellow>already present</>');

            return;
        }

        $this->callSilently('vendor:publish', [
            '--tag' => 'pandora-config',
            '--force' => true,
        ]);

        $this->components->twoColumnDetail('config/pandora.php', '<fg=green>published</>');
    }

    private function publishMigrations(): void
    {
        if ($this->option('force')) {
            $this->callSilently('vendor:publish', [
                '--tag' => 'pandora-migrations',
                '--force' => true,
            ]);

            $this->components->twoColumnDetail('migrations', '<fg=green>republished</>');

            return;
        }

        // Publish the ones this application does NOT already have, rather than
        // skipping the whole step because some are present. An upgrade that
        // adds a table would otherwise leave the host on the old schema, and
        // the first symptom would be a missing-table error in a page nobody
        // associates with a package update.
        //
        // Matched on the suffix, because a published migration may carry a
        // different timestamp prefix from the packaged one.
        $existing = collect(File::glob(database_path('migrations/*_create_pandora_*_table.php')))
            ->map(fn (string $path): string => $this->migrationSuffix($path))
            ->all();

        $missing = collect(File::glob(dirname(__DIR__, 3).'/database/migrations/*.php'))
            ->reject(fn (string $path): bool => in_array($this->migrationSuffix($path), $existing, true));

        if ($missing->isEmpty()) {
            $this->components->twoColumnDetail('migrations', '<fg=gray>up to date</>');

            return;
        }

        // Stamped with the moment of publishing when the application asks for
        // it, which is the same switch `vendor:publish` reads and the same
        // default. It matters because the packaged files are named
        // `0001_01_01_*` so they sort among themselves: a host that takes those
        // names verbatim can never order its own migrations relative to
        // Pandora's, since everything it writes sorts afterwards whatever it is
        // called. One second apart keeps the packaged order intact.
        $restamp = (bool) config('database.migrations.update_date_on_publish', false);
        $stamp = Date::now();

        foreach ($missing->values() as $index => $path) {
            $name = $restamp
                ? $stamp->addSeconds($index)->format('Y_m_d_His').'_'.$this->migrationSuffix($path)
                : basename($path);

            File::copy($path, database_path('migrations/'.$name));
        }

        $this->components->twoColumnDetail(
            'migrations',
            $existing === []
                ? '<fg=green>published</>'
                : "<fg=green>{$missing->count()} new migration(s) published</>",
        );
    }

    /**
     * The part of a migration filename that identifies WHAT it creates, with
     * the timestamp prefix removed.
     */
    private function migrationSuffix(string $path): string
    {
        return (string) preg_replace('/^\d[\d_]*_/', '', basename($path));
    }

    /**
     * @return bool whether the installation is expected to have a schema afterwards
     */
    private function runMigrations(): bool
    {
        if ($this->option('no-migrate')) {
            $this->components->twoColumnDetail('migrations', '<fg=yellow>skipped</>');

            return false;
        }

        // `--no-interaction` means "take the default answers", and the default
        // answer below is yes. It used to mean "do nothing and say so", which
        // made a scripted install print a yellow line nobody reads and exit 0
        // with no schema -- the failure then surfaced as a missing table in the
        // first page somebody opened. Opting out is what `--no-migrate` is for.
        if (! $this->input->isInteractive()) {
            $this->call('migrate', ['--force' => true]);

            return true;
        }

        if (! confirm('Run the Pandora migrations now?', default: true)) {
            $this->components->twoColumnDetail('migrations', '<fg=yellow>not run</>');

            return false;
        }

        $this->call('migrate');

        return true;
    }

    /**
     * Did the migrations that were supposed to run actually leave a schema?
     *
     * Checked rather than assumed, because `migrate` failing is not the only
     * way to end up here: a connection pointing somewhere else, a `--force`
     * refused, a database the user cannot create tables in. An installer that
     * reports success on the strength of having *called* migrate is reporting
     * on itself rather than on the installation.
     */
    private function schemaIsInstalled(): bool
    {
        /** @var string $prefix */
        $prefix = config('pandora.database.table_prefix', 'pandora_');

        try {
            return Schema::hasTable($prefix.'agents');
        } catch (Throwable) {
            // No reachable connection is a missing schema as far as the person
            // running this is concerned.
            return false;
        }
    }

    private function explainSetup(): void
    {
        $this->newLine();
        $this->components->info('Remaining setup');

        $this->line(<<<'TXT'
          <options=bold>1. Queue</>
             Pandora runs agents on a queue -- a web request is never held open for a run.
             Any driver works; Redis and Horizon are optional.

               php artisan queue:work

             Queue names are configurable under `pandora.queues` and collapse onto your
             default queue unless you split them.

          <options=bold>2. Realtime (optional)</>
             Reverb streams runs, tool calls and approvals live.

               composer require laravel/reverb
               php artisan reverb:install
               php artisan reverb:start

             Pandora works without it: the database is authoritative, so the UI falls back to
             polling and stays correct. Set PANDORA_REALTIME_ENABLED=false to opt out.

          <options=bold>3. Scheduler (needed for automations)</>
             Automations are driven by Laravel's scheduler.

               php artisan schedule:work

          <options=bold>4. Configure a provider</>
             The default is the built-in `fake` provider, which needs no credentials and is
             useful for verifying the installation. For a real one, set in .env:

               PANDORA_PROVIDER=openai
               PANDORA_OPENAI_API_KEY=...
               PANDORA_MODEL=gpt-4o-mini

          <options=bold>5. Register an agent</>
             No agent is created for you -- that is deliberate. Create an AgentDefinition
             class and list it in `pandora.agents.definitions`.

          <options=bold>6. Authorization</>
             Pandora defines a gate per ability. By default any authenticated user may access
             and chat, and every administrative ability is DENIED. Define gates of the same
             name in your application to take control.

          Then open <options=bold>/pandora</>, and run <options=bold>php artisan pandora:status</> to check things over.
        TXT);
    }
}
