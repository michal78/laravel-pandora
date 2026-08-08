<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Pandora\Mcp\Enums\McpTransport;
use Pandora\Mcp\Enums\ServerHealth;
use Pandora\Mcp\McpServer;

/**
 * Phase 6, criterion 14 — a server persists, and its credential does not live
 * here.
 *
 * The credential half is the reason this is a criterion rather than a model
 * test. The pressure to add "just an endpoint and a token field" arrives the
 * first time somebody registers a second server, and a field that accepts a
 * token is a field that shows one back. ADR-0014 keeps the secret in the
 * Phase 3 encrypted store; this row names a key and knows nothing else.
 */
it('persists a server with its transport, endpoint and health', function (): void {
    /** @var McpServer $server */
    $server = McpServer::query()->create([
        'name' => 'Ledger',
        'slug' => 'ledger',
        'namespace' => 'ledger',
        'transport' => McpTransport::Http->value,
        'endpoint' => 'https://mcp.example.test/rpc',
        'credential_key' => 'mcp.ledger',
    ]);

    expect($server->transport)->toBe(McpTransport::Http)
        ->and($server->health)->toBe(ServerHealth::Unknown)
        ->and($server->enabled)->toBeTrue()
        ->and($server->timeout_seconds)->toBe(30);
});

it('starts unknown rather than healthy', function (): void {
    // A server nobody has probed has not been shown to work. Defaulting to
    // healthy makes the first failure look like a bug in the run rather than
    // a server that was never reachable.
    expect((new McpServer)->health)->toBe(ServerHealth::Unknown);
});

it('has no credential column of any kind', function (): void {
    $columns = Schema::connection(config('pandora.database.connection'))
        ->getColumnListing((new McpServer)->getTable());

    foreach ($columns as $column) {
        expect($column)->not->toMatch('/secret|token|password|api_key|access_key|bearer/i');
    }
});

it('has no credential attribute a request could fill', function (): void {
    foreach ((new McpServer)->getFillable() as $attribute) {
        expect($attribute)->not->toMatch('/secret|token|password|api_key|access_key|bearer/i');
    }
});

it('names a credential key and nothing more', function (): void {
    /** @var McpServer $server */
    $server = McpServer::query()->create([
        'name' => 'Ledger',
        'slug' => 'ledger',
        'namespace' => 'ledger',
        'endpoint' => 'https://mcp.example.test/rpc',
        'credential_key' => 'mcp.ledger',
    ]);

    // Everything a reader of this row learns about how to authenticate: the
    // name of a key, which is useless without the application's own encrypted
    // store.
    expect(json_encode($server->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('secret')
        ->and($server->credential_key)->toBe('mcp.ledger');
});

it('treats an unhealthy server as unusable rather than slow', function (): void {
    /** @var McpServer $server */
    $server = McpServer::query()->create([
        'name' => 'Ledger', 'slug' => 'ledger', 'namespace' => 'ledger',
        'endpoint' => 'https://mcp.example.test/rpc',
    ]);

    expect($server->isUsable())->toBeTrue();

    $server->update(['health' => ServerHealth::Unhealthy->value]);

    // A run that waits on a server known to be down has converted a clear
    // failure into a timeout.
    expect($server->fresh()->isUsable())->toBeFalse();

    $server->update(['health' => ServerHealth::Healthy->value, 'enabled' => false]);

    expect($server->fresh()->isUsable())->toBeFalse();
});

it('keeps one tenant\'s servers out of another\'s', function (): void {
    inTenant('acme', function (): void {
        McpServer::query()->create([
            'name' => 'Acme ledger', 'slug' => 'ledger', 'namespace' => 'ledger',
            'endpoint' => 'https://acme.example.test/rpc',
        ]);
    });

    inTenant('globex', function (): void {
        expect(McpServer::query()->count())->toBe(0);
    });
});
