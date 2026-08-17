<?php

declare(strict_types=1);

use Pandora\Mcp\RemoteTool;
use Pandora\Tools\Tool;

/**
 * Phase 9, criterion 6 -- T6a, SSRF.
 *
 * The threat model describes an SSRF control: a host allowlist, DNS resolved
 * before connection, private and link-local ranges blocked, every redirect
 * re-validated. **None of it exists in `src/`, and neither does the thing it
 * guards.** Seventeen tools ship and not one makes an outbound request.
 *
 * So T6 is currently mitigated by absence. That is a real mitigation -- it is
 * the strongest one available, since a request that is never made cannot be
 * redirected anywhere -- and it is a fragile one, because it survives exactly
 * until somebody adds a fetch tool and nothing anywhere says they may not.
 *
 * Writing the allowlist now would be worse than this test: unverifiable code
 * guarding a surface that does not exist, green forever, and wrong the moment
 * anyone relied on it. This is the honest control instead. It states the
 * current fact as an invariant, and the day a core tool grows an outbound
 * request is the day CI goes red and somebody has to build T6 for real.
 *
 * **Two things this cannot see, named rather than implied.**
 *
 * A tool that calls a Pandora service which itself makes a request. The scan
 * is one level deep -- the tool's own source and the types in its constructor
 * -- because following the call graph through `ToolResult` and `ToolContext`
 * reaches most of the package and stops distinguishing anything.
 *
 * A tool writing to an object-storage disk. `WorkspaceTool` on an S3 disk does
 * cause an outbound request, several layers down. It is not SSRF: the endpoint
 * comes from `filesystems.php`, the bucket from a workspace row, and neither
 * is selectable by model output. That is the same distinction T6b draws for
 * the MCP transport, and it is why this test looks for HTTP *clients* rather
 * than for network traffic.
 */

/**
 * Every shipped tool, as [class => path], excluding the one remote by design.
 *
 * @return array<class-string, string>
 */
function pandoraToolClasses(): array
{
    $tools = [];

    foreach (pandoraSourceClasses() as $class => $path) {
        if (! is_subclass_of($class, Tool::class)) {
            continue;
        }

        // The one tool whose entire job is to call somewhere else. Named here
        // rather than skipped by a pattern, so a second exception has to be
        // added on purpose -- and T6b is the test that governs where its
        // request is allowed to go.
        if ($class === RemoteTool::class) {
            continue;
        }

        $tools[$class] = $path;
    }

    return $tools;
}

it('finds the tools to check', function (): void {
    // A rule that iterates nothing passes for the wrong reason. Sixteen core
    // tools ship today; the floor is deliberately below that, so removing one
    // does not fail this, but replacing the selector with something that
    // matches nothing does.
    expect(count(pandoraToolClasses()))->toBeGreaterThanOrEqual(10);
});

it('makes no outbound HTTP request from any core tool', function (): void {
    $needles = [
        'Illuminate\\Support\\Facades\\Http',
        'Illuminate\\Http\\Client',
        'GuzzleHttp',
        'Symfony\\Component\\HttpClient',
        'curl_init',
        'curl_exec',
        'fsockopen',
        'stream_socket_client',
        'Http::',
    ];

    $offenders = [];

    foreach (pandoraToolClasses() as $class => $path) {
        $source = (string) file_get_contents($path);

        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = "{$class} references {$needle}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('opens no URL through a stream wrapper from any core tool', function (): void {
    // `file_get_contents()` and `fopen()` are file functions until the string
    // starts with a scheme, at which point they are an HTTP client with no
    // timeout, no size cap and no redirect limit. A tool has no business
    // calling either -- workspace reads go through a Laravel disk.
    $offenders = [];

    foreach (pandoraToolClasses() as $class => $path) {
        $source = (string) file_get_contents($path);

        foreach (['file_get_contents', 'fopen', 'readfile', 'copy'] as $function) {
            if (preg_match('/(?<![\w>:$])'.$function.'\s*\(/', $source) === 1) {
                $offenders[] = "{$class} calls {$function}()";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('injects no HTTP client into any core tool', function (): void {
    // The source scan above reads the file; this reads the wiring. A tool
    // handed a client by the container never has to name one.
    $forbidden = [
        'Illuminate\Http\Client\Factory',
        'Illuminate\Http\Client\PendingRequest',
        'GuzzleHttp\Client',
        'GuzzleHttp\ClientInterface',
        'Psr\Http\Client\ClientInterface',
    ];

    $offenders = [];

    foreach (array_keys(pandoraToolClasses()) as $class) {
        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            continue;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if (in_array($type->getName(), $forbidden, true)) {
                $offenders[] = "{$class} is given {$type->getName()}";
            }
        }
    }

    expect($offenders)->toBe([]);
});
