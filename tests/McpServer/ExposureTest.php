<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Mcp\Server\Exposure;
use Pandora\Tools\BuiltIn\BuiltInTools;
use Pandora\Tools\ToolRegistry;

/**
 * Phase 6, criterion 26 — the MCP server is disabled by default and exposes
 * nothing the allowlist does not name.
 *
 * The default is the whole criterion. Installing a package must expose nothing
 * at all: a server that ships on, or ships serving "whatever is registered",
 * publishes an installation's tools to anybody who finds the endpoint
 * (ADR-0014).
 */
beforeEach(function (): void {
    app(ToolRegistry::class)->flush()->registerMany(BuiltInTools::all());

    $this->rpc = fn (string $method, array $params = []) => $this->postJson(
        '/pandora/'.config('pandora.mcp.server.path', 'mcp'),
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
    );
});

it('ships disabled', function (): void {
    // Read from the package's own config, not from a test that turned it on.
    expect(config('pandora.mcp.server.enabled'))->toBeFalse()
        ->and(config('pandora.mcp.server.exposed_tools'))->toBe([]);
});

it('registers no route at all while it is disabled', function (): void {
    // Not "returns 403" -- the endpoint does not exist. A disabled server
    // should not confirm it exists and could be turned on.
    expect(app('router')->getRoutes()->getByName('pandora.mcp.server'))->toBeNull();
});

it('exposes nothing when enabled with an empty allowlist', function (): void {
    config()->set('pandora.mcp.server.enabled', true);

    // Enabled is not the same as exposing. An operator who turned the server
    // on has said where it listens, not what it serves.
    expect(app(Exposure::class)->tools())->toBe([]);
});

it('exposes only what the allowlist names', function (): void {
    config()->set('pandora.mcp.server.enabled', true);
    config()->set('pandora.mcp.server.exposed_tools', ['inspect_run_status']);

    $names = array_map(
        static fn ($tool): string => $tool->name(),
        app(Exposure::class)->tools(),
    );

    expect($names)->toBe(['inspect_run_status'])
        // Registered and not named: absent.
        ->and($names)->not->toContain('remember');
});

it('answers the same way for a tool that is not exposed and one that does not exist', function (): void {
    config()->set('pandora.mcp.server.enabled', true);
    config()->set('pandora.mcp.server.exposed_tools', ['inspect_run_status']);

    $exposure = app(Exposure::class);

    // A caller learns what this server serves, not what this installation has.
    expect($exposure->find('remember'))->toBeNull()
        ->and($exposure->find('no_such_tool_anywhere'))->toBeNull();
});

it('records an attempt to reach something not exposed, at warning', function (): void {
    config()->set('pandora.mcp.server.enabled', true);
    config()->set('pandora.mcp.server.exposed_tools', ['inspect_run_status']);

    app(Exposure::class)->find('remember');

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'mcp.exposure_denied')->firstOrFail();

    // Somebody with a valid token asking for something we do not serve is
    // either a misconfiguration to fix or somebody probing.
    expect($entry->severity)->toBe('warning')
        ->and($entry->metadata['tool'] ?? null)->toBe('remember');
});

it('ignores an allowlist entry naming a tool that is not installed', function (): void {
    config()->set('pandora.mcp.server.enabled', true);
    config()->set('pandora.mcp.server.exposed_tools', ['inspect_run_status', 'from_a_removed_package']);

    // An operator listing a tool from a package they later removed should not
    // take the server down.
    expect(array_map(
        static fn ($tool): string => $tool->name(),
        app(Exposure::class)->tools(),
    ))->toBe(['inspect_run_status']);
});

it('serves tools and nothing else', function (): void {
    config()->set('pandora.mcp.server.enabled', true);

    // Resources, prompts and sampling are out. Sampling in particular inverts
    // the trust direction: a remote end asking us to spend a model call on its
    // behalf is a budget hole with a protocol around it.
    $source = (string) file_get_contents(__DIR__.'/../../src/Mcp/Server/McpServerController.php');

    expect($source)->not->toContain("'resources/")
        ->and($source)->not->toContain("'prompts/")
        ->and($source)->not->toContain("'sampling/");
});
