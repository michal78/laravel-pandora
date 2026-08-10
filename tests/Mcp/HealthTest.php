<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Mcp\Discovery;
use Pandora\Mcp\Enums\ServerHealth;
use Pandora\Mcp\HealthProbe;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\McpTool;
use Pandora\Mcp\McpToolApproval;
use Pandora\Mcp\RemoteToolResolver;
use Pandora\Mcp\Transport\HttpTransport;
use Pandora\Testing\FakeMcpServer;
use Pandora\Tests\Support\MakesTools;

/**
 * Phase 6, criterion 24 — an unhealthy server's tools are unavailable, and the
 * run says so rather than waiting.
 *
 * Unchanged from the Phase 3 provider rule and right for the same reason. A
 * run that waits on a server known to be down has converted a clear failure
 * into a timeout, and a timeout is the failure operators debug last because it
 * looks like load.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    config()->set('pandora.mcp.client.enabled', true);

    $this->fake = new FakeMcpServer;
    app()->bind(HttpTransport::class, fn () => $this->fake);

    /** @var McpServer $server */
    $server = McpServer::query()->create([
        'name' => 'Ledger', 'slug' => 'ledger', 'namespace' => 'ledger',
        'endpoint' => 'https://mcp.example.test/rpc',
    ]);

    $this->server = $server;
    $this->fake->offer('lookup_invoice', 'Look up an invoice.');

    app(Discovery::class)->run($server);

    $this->context = $this->toolContext();

    /** @var McpTool $tool */
    $tool = McpTool::query()->firstOrFail();

    McpToolApproval::query()->create([
        'agent_id' => $this->context->agent->getKey(),
        'mcp_tool_id' => $tool->getKey(),
        'approved_schema_hash' => $tool->schema_hash,
        'approved_at' => now(),
    ]);

    $this->available = fn (): array => array_map(
        static fn ($tool): string => $tool->name(),
        app(RemoteToolResolver::class)->available($this->context->agent),
    );
});

it('marks a healthy server healthy', function (): void {
    expect(app(HealthProbe::class)->probe($this->server))->toBe(ServerHealth::Healthy);
});

it('degrades on one failure rather than condemning the server', function (): void {
    $this->fake->unreachable();

    // A server that flapped on a single reset connection would pull its tools
    // out from under every agent for no reason anybody could later explain.
    expect(app(HealthProbe::class)->probe($this->server))->toBe(ServerHealth::Degraded)
        ->and(($this->available)())->toContain('ledger-lookup_invoice');
});

it('goes unhealthy on a run of failures', function (): void {
    $this->fake->unreachable();

    app(HealthProbe::class)->probe($this->server);
    $health = app(HealthProbe::class)->probe($this->server->refresh());

    expect($health)->toBe(ServerHealth::Unhealthy);
});

it('withdraws the tools of an unhealthy server', function (): void {
    $this->fake->unreachable();

    app(HealthProbe::class)->probe($this->server);
    app(HealthProbe::class)->probe($this->server->refresh());

    // Unavailable rather than slow. The run is told there is no such tool
    // instead of spending its timeout finding out.
    expect(($this->available)())->toBe([]);
});

it('records an unreachable server at warning', function (): void {
    $this->fake->unreachable();

    app(HealthProbe::class)->probe($this->server);

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'mcp.server_unreachable')->firstOrFail();

    expect($entry->severity)->toBe('warning')
        ->and($entry->metadata['consecutive_failures'] ?? null)->toBe(1)
        ->and($entry->metadata['health'] ?? null)->toBe('degraded');
});

it('recovers, and forgets the failures it had counted', function (): void {
    $this->fake->unreachable();
    app(HealthProbe::class)->probe($this->server);

    $this->fake->unreachable(false);
    expect(app(HealthProbe::class)->probe($this->server->refresh()))->toBe(ServerHealth::Healthy);

    // The counter resets, so a server that fails once a week never accumulates
    // its way to unhealthy.
    $this->fake->unreachable();
    expect(app(HealthProbe::class)->probe($this->server->refresh()))->toBe(ServerHealth::Degraded);
});

it('keeps a disabled server\'s tools away regardless of health', function (): void {
    $this->server->update(['enabled' => false]);

    expect(($this->available)())->toBe([]);
});

it('says a healthy server is usable and an unhealthy one is not', function (): void {
    expect($this->server->isUsable())->toBeTrue();

    $this->server->update(['health' => ServerHealth::Degraded->value]);
    // Degraded still serves: it says "failed once, nothing concluded yet".
    expect($this->server->fresh()->isUsable())->toBeTrue();

    $this->server->update(['health' => ServerHealth::Unhealthy->value]);
    expect($this->server->fresh()->isUsable())->toBeFalse();
});
