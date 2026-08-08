<?php

declare(strict_types=1);

use Pandora\Contracts\ContextProvider;
use Pandora\Contracts\Provider;
use Pandora\Exceptions\PandoraException;
use Pandora\Jobs\ContinueAgentRun;
use Pandora\Jobs\ExecuteToolCall;
use Pandora\Realtime\Events\PandoraBroadcastEvent;
use Pandora\Runs\RunStateMachine;
use Pandora\Tools\BuiltIn\BuiltInTools;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolGatekeeper;

/**
 * Acceptance guarantee 21 -- architectural invariants.
 *
 * These rules are stated in the documentation; enforcing them here is what
 * keeps them true as the codebase grows rather than aspirational.
 *
 * Implemented with plain reflection over the source tree instead of Pest's
 * arch plugin: that plugin cannot build its file index in this package's
 * nested-vendor layout (see docs/development/open-questions.md, Q1). Reflection
 * checks the same properties and works everywhere.
 */

/**
 * Every PHP file under src/, as [class => path].
 *
 * @return array<class-string, string>
 */
function pandoraSourceClasses(): array
{
    static $classes = null;

    if ($classes !== null) {
        return $classes;
    }

    $root = dirname(__DIR__, 2).'/src';
    $classes = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = 'Pandora\\'.str_replace('/', '\\', $relative);

        if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
            $classes[$class] = $file->getPathname();
        }
    }

    ksort($classes);

    return $classes;
}

it('finds the source tree', function (): void {
    expect(pandoraSourceClasses())->not->toBeEmpty();
});

it('declares strict types in every source file', function (): void {
    $offenders = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        if (! str_contains((string) file_get_contents($path), 'declare(strict_types=1)')) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});

it('leaves no debugging statements behind', function (): void {
    $offenders = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        $source = (string) file_get_contents($path);

        foreach (['dd', 'dump', 'var_dump', 'print_r', 'ray'] as $function) {
            // Require a real call site: not preceded by a word character
            // (so `array(` does not match `ray(`), a `->`, or a `::`.
            if (preg_match('/(?<![\w>:$])'.$function.'\s*\(/', $source) === 1) {
                $offenders[] = "{$class} calls {$function}()";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('confines vendor SDK types to their own adapter directory', function (): void {
    // The rule that stops a vendor's minor release becoming a breaking change
    // for every host application.
    $offenders = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        if (str_contains($class, 'Providers\\Adapters')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        foreach (['use OpenAI', 'use Anthropic', 'use Google\\Cloud', 'use GuzzleHttp'] as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = "{$class} references {$needle}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps everything in Contracts an interface', function (): void {
    foreach (array_keys(pandoraSourceClasses()) as $class) {
        if (str_starts_with($class, 'Pandora\Contracts\\')) {
            expect(interface_exists($class))->toBeTrue("{$class} must be an interface");
        }
    }
});

it('keeps everything in an Enums namespace an enum', function (): void {
    foreach (array_keys(pandoraSourceClasses()) as $class) {
        if (str_contains($class, '\\Enums\\')) {
            expect(enum_exists($class))->toBeTrue("{$class} must be an enum");
        }
    }
});

it('makes every provider data object readonly', function (): void {
    foreach (array_keys(pandoraSourceClasses()) as $class) {
        if (! str_starts_with($class, 'Pandora\Providers\Data\\') || enum_exists($class)) {
            continue;
        }

        expect((new ReflectionClass($class))->isReadOnly())
            ->toBeTrue("{$class} must be readonly -- DTOs are immutable");
    }
});

it('routes every broadcast through the redacting base class', function (): void {
    // There is no code path that can emit an unredacted payload, because
    // redaction lives in the base class's broadcastWith().
    foreach (array_keys(pandoraSourceClasses()) as $class) {
        if ($class === PandoraBroadcastEvent::class) {
            continue;
        }

        if (str_starts_with($class, 'Pandora\Realtime\Events\\')) {
            expect(is_subclass_of($class, PandoraBroadcastEvent::class))
                ->toBeTrue("{$class} must extend PandoraBroadcastEvent");
        }
    }
});

it('derives every exception from the Pandora hierarchy', function (): void {
    foreach (array_keys(pandoraSourceClasses()) as $class) {
        if (! str_starts_with($class, 'Pandora\Exceptions\\')) {
            continue;
        }

        if ($class === PandoraException::class) {
            continue;
        }

        expect(is_subclass_of($class, PandoraException::class))
            ->toBeTrue("{$class} must extend PandoraException so it can be classified");
    }
});

it('keeps context providers behind their contract', function (): void {
    foreach (array_keys(pandoraSourceClasses()) as $class) {
        if (str_starts_with($class, 'Pandora\Context\Providers\\')) {
            expect(is_subclass_of($class, ContextProvider::class))
                ->toBeTrue("{$class} must implement ContextProvider");
        }
    }
});

it('keeps HTTP and Livewire out of the execution domain', function (): void {
    $offenders = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        $inDomain = str_starts_with($class, 'Pandora\Runs\\')
            || str_starts_with($class, 'Pandora\Providers\\')
            || str_starts_with($class, 'Pandora\Context\\')
            || str_starts_with($class, 'Pandora\Tools\\')
            || str_starts_with($class, 'Pandora\Approvals\\');

        if (! $inDomain) {
            continue;
        }

        $source = (string) file_get_contents($path);

        foreach (['use Livewire\\', 'use Illuminate\\Http\\Request'] as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = "{$class} references {$needle}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('has exactly one component permitted to transition run state', function (): void {
    // The invariant that keeps run state coherent: nothing outside the state
    // machine may write the `state` column.
    $offenders = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        if ($class === RunStateMachine::class) {
            continue;
        }

        $source = (string) file_get_contents($path);

        // Direct assignment to a run's state outside the state machine.
        if (preg_match('/\$run->state\s*=[^=]/', $source) === 1) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps every tool behind the Tool base class', function (): void {
    // The base class is where validation, schema generation and the deny-by-
    // default authorize() live. A "tool" that skipped it would skip those.
    foreach (array_keys(pandoraSourceClasses()) as $class) {
        if (! str_starts_with($class, 'Pandora\Tools\BuiltIn\\')) {
            continue;
        }

        if ($class === BuiltInTools::class) {
            continue;
        }

        expect(is_subclass_of($class, Tool::class))
            ->toBeTrue("{$class} must extend Tool");
    }
});

it('lets nothing but the gatekeeper decide a tool call', function (): void {
    // Every layer runs, in order, in one place. A second call site is a second
    // chance to forget one.
    $offenders = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        if ($class === ToolGatekeeper::class) {
            continue;
        }

        $source = (string) file_get_contents($path);

        // Calling a tool's authorize() outside the gatekeeper means some other
        // component decided a call was permitted on its own.
        if (preg_match('/->authorize\(\s*\$input/', $source) === 1
            && ! str_starts_with($class, 'Pandora\Tools\BuiltIn\\')) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});

it('executes a tool from exactly one place', function (): void {
    // handle() is reached through ExecuteToolCall and nowhere else, which is
    // what makes idempotency and re-authorization unavoidable rather than
    // conventional.
    $offenders = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        if ($class === ExecuteToolCall::class || str_starts_with($class, 'Pandora\Tools\\')) {
            continue;
        }

        if (preg_match('/->handle\(\s*\$input\s*,/', (string) file_get_contents($path)) === 1) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});

it('ships no god object', function (): void {
    foreach (['AgentService', 'PandoraManager', 'PandoraService'] as $forbidden) {
        foreach (array_keys(pandoraSourceClasses()) as $class) {
            expect(class_basename($class))->not->toBe($forbidden);
        }
    }
});

it('keeps every adapter behind the Provider contract', function (): void {
    foreach (array_keys(pandoraSourceClasses()) as $class) {
        if (! str_starts_with($class, 'Pandora\Providers\Adapters\\')
            || str_contains($class, '\\Concerns\\')) {
            continue;
        }

        expect(is_subclass_of($class, Provider::class))
            ->toBeTrue("{$class} must implement Provider");
    }
});

it('confines an adapter to translation', function (): void {
    // An adapter translates between one vendor and Pandora's DTOs. One that
    // knew about routing, the catalog or a run would be making decisions that
    // belong above it, and the next adapter would have to make them again.
    $offenders = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        if (! str_starts_with($class, 'Pandora\Providers\Adapters\\')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        foreach ([
            'Providers\\Routing\\',
            'Providers\\Catalog\\ModelCatalog',
            'Providers\\Health\\',
            'Pandora\\Runs\\',
            'Pandora\\Usage\\',
        ] as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = "{$class} references {$needle}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('reads a credential secret in exactly the places that send one', function (): void {
    // `secret()` is a method rather than a property precisely so this rule can
    // exist: every read is one greppable call, and the only legitimate readers
    // are the adapters that put it in a header.
    $permitted = [
        'Pandora\Providers\Credentials\Credential',
        'Pandora\Providers\Credentials\ProviderCredential',
        // The MCP client puts a server's credential in an Authorization
        // header, which is the same job an adapter does and the same reason
        // it is allowed to read one. Listed by name rather than by namespace
        // prefix, so a second class under Mcp\Transport that starts reading
        // secrets has to be added here deliberately.
        'Pandora\Mcp\Transport\HttpTransport',
    ];

    $offenders = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        if (in_array($class, $permitted, true)
            || str_starts_with($class, 'Pandora\Providers\Adapters\\')) {
            continue;
        }

        if (str_contains((string) file_get_contents($path), '->secret()')) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});

it('records usage from exactly one place', function (): void {
    // A second call site is a second chance to record a call twice, or not at
    // all, and the bill is where that shows up.
    $offenders = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        if ($class === ContinueAgentRun::class || str_starts_with($class, 'Pandora\Usage\\')) {
            continue;
        }

        if (preg_match('/UsageRecorder\s+\$/', (string) file_get_contents($path)) === 1) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});
