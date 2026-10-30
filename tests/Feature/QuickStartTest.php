<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Orchestra\Testbench\default_migration_path;

use Pandora\Agents\Agent;
use Pandora\Conversations\Conversation;
use Pandora\Providers\Adapters\FakeProvider;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\ProviderManager;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Support\ConfigDefaults;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolExecution;

/**
 * Phase 9, criterion 29 -- tests/Fixtures/ExampleApp runs the documented
 * quick start end to end: installed, migrated, one agent, one tool call,
 * from the README's own commands. (The criterion also names a conversation;
 * the console quick start has none by design -- see the assertion below.)
 *
 * The README is read, not paraphrased. Its PHP blocks must be byte-identical
 * to the example application's files, and its commands are the ones executed
 * here, in order. Change the README and this either runs the change or fails.
 *
 * ONE thing is added, and it is the model. The fake provider never decides to
 * call a tool -- it is not a model -- so it is scripted to make the decision a
 * real one would. Everything either side of that decision is the README's.
 */
function readme(): string
{
    return File::get(dirname(__DIR__, 2).'/README.md');
}

function exampleApp(string $path = ''): string
{
    return dirname(__DIR__).'/Fixtures/ExampleApp'.($path === '' ? '' : '/'.$path);
}

/**
 * The fenced blocks of one README section, in order.
 *
 * @return list<array{lang: string, body: string}>
 */
function readmeBlocks(string $heading): array
{
    $readme = readme();
    $start = strpos($readme, "\n{$heading}\n");

    expect($start)->not->toBeFalse("README has no {$heading} section");

    $rest = substr($readme, (int) $start + strlen($heading) + 2);
    // Up to the next heading of ANY level: `## Installation` contains the
    // quick start as a subsection, and its install steps are not the run.
    $end = preg_match('/\n#+ /', $rest, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : strlen($rest);

    preg_match_all('/```(\w*)\n(.*?)\n```/s', substr($rest, 0, (int) $end), $blocks, PREG_SET_ORDER);

    return array_map(static fn (array $block): array => ['lang' => $block[1], 'body' => $block[2]."\n"], $blocks);
}

/**
 * The README's shell commands for a section, one per line.
 *
 * @return list<string>
 */
function readmeCommands(string $heading): array
{
    $commands = [];

    foreach (readmeBlocks($heading) as $block) {
        if ($block['lang'] === 'bash') {
            $commands = [...$commands, ...array_filter(explode("\n", trim($block['body'])))];
        }
    }

    return $commands;
}

/**
 * Run one README command the way a shell would.
 *
 * Two are refused by name, each for a reason that is not "it was
 * inconvenient": `composer require` of this package is what the test
 * environment already is, and `queue:work` is a daemon -- the quick start's
 * own run waits for its result, so nothing it does needs a worker.
 */
function runReadmeCommand(string $command): string
{
    if ($command === 'composer require michal78/laravel-pandora') {
        expect(json_decode(File::get(dirname(__DIR__, 2).'/composer.json'), true)['name'])
            ->toBe('michal78/laravel-pandora');

        return '';
    }

    if ($command === 'php artisan queue:work') {
        return '';
    }

    expect($command)->toStartWith('php artisan ', "the quick start runs a command this test does not know: {$command}");

    $words = str_getcsv(substr($command, strlen('php artisan ')), ' ', '"', '');
    $name = (string) array_shift($words);

    $arguments = [];
    $positional = 0;
    $signature = Artisan::all()[$name]->getDefinition();

    foreach ($words as $word) {
        if (str_starts_with((string) $word, '--')) {
            $arguments[(string) $word] = true;

            continue;
        }

        $arguments[$signature->getArgument($positional++)->getName()] = $word;
    }

    $exit = Artisan::call($name, $arguments);
    $output = Artisan::output();

    expect($exit)->toBe(0, "`{$command}` exited {$exit}:\n{$output}");

    return $output;
}

/**
 * Point the application at the database this test owns.
 *
 * SQLite `:memory:` cannot be used: the quick start edits the published
 * config, and seeing an edit means booting again, which would hand the
 * application a new empty database. A file survives the reboot, the way a
 * host's database does.
 */
function quickStartSuffix(string $path): string
{
    return (string) preg_replace('/^\d[\d_]*_/', '', basename($path));
}

function useQuickStartDatabase(string $file): void
{
    if (config('database.connections.testing.driver') === 'sqlite') {
        config(['database.connections.testing.database' => $file]);
        DB::purge('testing');
    }
}

it('shows the example application, byte for byte', function (): void {
    $php = array_values(array_filter(readmeBlocks('### Quick start'), static fn (array $b): bool => $b['lang'] === 'php'));

    expect($php)->toHaveCount(3)
        ->and($php[0]['body'])->toBe(File::get(exampleApp('app/Tools/LookupOrder.php')))
        ->and($php[1]['body'])->toBe(File::get(exampleApp('app/Agents/SupportAgent.php')))
        ->and($php[2]['body'])->toBe(File::get(exampleApp('config/pandora.fragment')));
});

it('runs the quick start end to end from the README\'s own commands', function (): void {
    $database = sys_get_temp_dir().'/pandora-quickstart-'.uniqid().'.sqlite';
    touch($database);

    $copied = [];
    $created = [];

    try {
        // A clean application: no published config, no published migrations,
        // and -- after the reboot -- no migration path the harness registered.
        File::delete(config_path('pandora.php'));
        test()->refreshApplication();
        useQuickStartDatabase($database);
        Artisan::call('db:wipe', ['--drop-views' => true, '--force' => true]);

        // 1. Install, exactly as the README says.
        foreach (readmeCommands('## Installation') as $command) {
            if (str_starts_with($command, 'composer require') && $command !== 'composer require michal78/laravel-pandora') {
                continue; // Livewire, and the unstable branch: options, not steps.
            }

            runReadmeCommand($command);
        }

        expect(File::exists(config_path('pandora.php')))->toBeTrue('the installer did not publish the config');

        // 2. The example application's files, where the README puts them.
        foreach (['app/Tools/LookupOrder.php', 'app/Agents/SupportAgent.php'] as $path) {
            if (! File::isDirectory(dirname(base_path($path)))) {
                $created[] = dirname(base_path($path));
            }

            File::ensureDirectoryExists(dirname(base_path($path)));
            File::copy(exampleApp($path), base_path($path));
            $copied[] = base_path($path);

            require_once base_path($path);
        }

        // 3. The README's registration, applied to the file the installer
        //    published -- its keys set, everything else left as published.
        $fragment = (string) preg_replace('/^\/\/.*\n/', '', File::get(exampleApp('config/pandora.fragment')));
        $registration = eval("return [{$fragment}];");

        /** @var array<string, mixed> $published */
        $published = require config_path('pandora.php');

        File::put(
            config_path('pandora.php'),
            '<?php return '.var_export(ConfigDefaults::merge($published, $registration), true).';',
        );

        test()->refreshApplication();
        useQuickStartDatabase($database);

        // The model's decision -- the one thing a test has to supply.
        /** @var FakeProvider $model */
        $model = app(ProviderManager::class)->provider('fake');
        $model->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => '1234'])])
            ->willRespondWith('Order 1234 shipped on Tuesday.');

        // 4. Run it, exactly as the README says.
        $commands = readmeCommands('### Quick start');

        expect($commands)->toBe([
            'php artisan pandora:agent:list',
            'php artisan pandora:agent:run support "Where is order 1234?" --trace',
        ]);

        expect(runReadmeCommand($commands[0]))->toContain('support');

        $output = runReadmeCommand($commands[1]);

        // One agent, one tool call, one completed run.
        //
        // And NO conversation: the criterion names one, and the console path
        // the quick start uses has none by design -- a session, not a
        // conversation, is the isolation boundary, and a conversation is what
        // the chat page or `inConversation()` creates. Asserted rather than
        // skipped, so the reading recorded in phase-9-acceptance.md stays true.
        $run = Run::query()->sole();
        $execution = ToolExecution::query()->sole();

        expect(Agent::query()->pluck('slug')->all())->toBe(['support'])
            ->and(Conversation::query()->count())->toBe(0)
            ->and($run->session_id)->not->toBeNull()
            ->and($run->state)->toBe(RunState::Completed)
            ->and($run->output)->toBe('Order 1234 shipped on Tuesday.')
            ->and($execution->tool_name)->toBe('lookup_order')
            ->and($execution->status)->toBe(ToolExecutionStatus::Succeeded)
            ->and(json_encode($execution->result))->toContain('Order 1234 shipped on Tuesday.')
            ->and($output)->toContain('Order 1234 shipped on Tuesday.');
    } finally {
        foreach ($copied as $path) {
            File::delete($path);
        }

        // The skeleton persists between runs, so a directory this test made
        // is removed too -- only if it made it, and only once it is empty.
        foreach ($created as $directory) {
            if (File::isDirectory($directory) && File::isEmptyDirectory($directory)) {
                File::deleteDirectory($directory);
            }
        }

        File::delete(config_path('pandora.php'));

        $packaged = array_map(quickStartSuffix(...), File::glob(dirname(__DIR__, 2).'/database/migrations/*.php'));

        foreach (File::glob(database_path('migrations/*.php')) as $path) {
            if (in_array(quickStartSuffix($path), $packaged, true)) {
                File::delete($path);
            }
        }

        // Leave the schema the harness would have built, on the connection the
        // harness will use, for its teardown and for whatever runs next.
        test()->refreshApplication();
        Artisan::call('db:wipe', ['--drop-views' => true, '--force' => true]);

        foreach ([default_migration_path(), dirname(__DIR__, 2).'/database/migrations'] as $path) {
            Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]);
        }

        File::delete($database);
    }
});
