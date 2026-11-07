<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\Transport\HttpTransport;

/**
 * JSON-RPC 2.0 says `params`, when present, is an object or an array, and MCP
 * methods take an object. PHP encodes an empty array as `[]`, so `tools/list`
 * went out as `"params":[]` — which Home Assistant's built-in MCP server
 * rejects with HTTP 400 "Request must be a JSON-RPC message", and Discovery
 * reported as an unavailable server.
 *
 * These tests use the real `HttpTransport` against `Http::fake()` and read the
 * raw request body, because a decoded array cannot tell `[]` from `{}`.
 */
beforeEach(function (): void {
    config()->set('pandora.mcp.client.enabled', true);

    /** @var McpServer $server */
    $server = McpServer::query()->create([
        'name' => 'Ledger',
        'slug' => 'ledger',
        'namespace' => 'ledger',
        'endpoint' => 'https://mcp.example.test/rpc',
    ]);

    $this->server = $server;

    Http::fake([
        'mcp.example.test/*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => '1',
            'result' => ['tools' => [], 'content' => []],
        ]),
    ]);
});

it('sends tools/list params as a JSON object, not an empty array', function (): void {
    app(HttpTransport::class)->listTools($this->server);

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        // The raw bytes are the contract. `"params":[]` is the 400.
        expect($body)->toContain('"params":{}')
            ->and($body)->not->toContain('"params":[]');

        $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);

        expect($decoded->method)->toBe('tools/list')
            ->and($decoded->params)->toBeInstanceOf(stdClass::class);

        return true;
    });
});

it('leaves non-empty params unchanged', function (): void {
    app(HttpTransport::class)->callTool($this->server, 'lookup_invoice', ['number' => 'INV-42']);

    Http::assertSent(function (Request $request): bool {
        $decoded = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);

        expect($decoded['method'])->toBe('tools/call')
            ->and($decoded['params'])->toBe([
                'name' => 'lookup_invoice',
                'arguments' => ['number' => 'INV-42'],
            ]);

        return true;
    });
});

it('still sends empty tool arguments as a JSON object', function (): void {
    app(HttpTransport::class)->callTool($this->server, 'lookup_invoice', []);

    Http::assertSent(function (Request $request): bool {
        expect($request->body())->toContain('"arguments":{}');

        return true;
    });
});
