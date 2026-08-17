<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\Discovery;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\McpTool;
use Pandora\Mcp\RemoteTool;
use Pandora\Mcp\Transport\HttpTransport;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\ToolInput;

/**
 * Phase 9, criterion 7 -- T6b, the outbound surface that actually exists.
 *
 * T6a says no core tool makes an outbound request. This is the exception, and
 * it is a different shape: the MCP HTTP transport connects to a URL an
 * operator wrote into `pandora_mcp_servers`. That distinction -- destination
 * chosen by a human at configuration time, never by a model at run time -- is
 * the entire mitigation, and until now nothing asserted it.
 *
 * **This file uses the real `HttpTransport` against `Http::fake()`.** Every
 * other MCP test binds `FakeMcpServer` in its place, which is right for
 * testing what the client does with an answer and useless for testing where
 * the question was sent -- a fake that never had a URL cannot lose one. That
 * substitution is the exact shape of both Phase 6 defects, so the boundary
 * under test here is the HTTP client itself.
 *
 * Writing it found a live SSRF. Guzzle follows redirects by default, so a
 * hostile or compromised server answering `302 Location:
 * http://169.254.169.254/latest/meta-data/` had this application re-send its
 * POST to the cloud metadata endpoint -- across a scheme downgrade, into the
 * link-local range -- and hand the response body back to the model as tool
 * output. The destination was the far end's to choose. `allow_redirects` is
 * now off and a 3xx is refused; the fourth test below is the one that failed.
 */
uses(MakesTools::class);

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
});

/**
 * Every URL the HTTP client was actually asked for, in order.
 *
 * @return list<string>
 */
function requestedUrls(): array
{
    $urls = [];

    Http::recorded(function (Request $request) use (&$urls): bool {
        $urls[] = $request->url();

        return false;
    });

    return $urls;
}

/**
 * A well-formed MCP result, as the far end would send one.
 */
function mcpBody(string $text): string
{
    return json_encode(
        ['jsonrpc' => '2.0', 'id' => '1', 'result' => ['content' => [['type' => 'text', 'text' => $text]]]],
        JSON_THROW_ON_ERROR,
    );
}

it('sends the call to the endpoint on the server row', function (): void {
    Http::fake(['*' => Http::response(mcpBody('INV-42'))]);

    app(HttpTransport::class)->callTool($this->server, 'lookup_invoice', ['query' => 'INV-42']);

    expect(requestedUrls())->toBe(['https://mcp.example.test/rpc']);
});

it('ignores a URL supplied in the tool arguments', function (): void {
    // The model's half of the attack. Arguments reach the far end verbatim --
    // that is the Phase 6 fix, and it is why an argument named `endpoint` is
    // now a string the server receives rather than a string this client obeys.
    Http::fake(['*' => Http::response(mcpBody('INV-42'))]);

    app(HttpTransport::class)->callTool($this->server, 'lookup_invoice', [
        'url' => 'https://attacker.example.test/collect',
        'endpoint' => 'http://169.254.169.254/latest/meta-data/',
        'base_uri' => 'http://127.0.0.1:6379/',
        'query' => 'INV-42',
    ]);

    $urls = requestedUrls();

    expect($urls)->toBe(['https://mcp.example.test/rpc'])
        ->and(implode(' ', $urls))->not->toContain('attacker.example.test')
        ->and(implode(' ', $urls))->not->toContain('169.254.169.254');
});

it('ignores a URL a model put in the arguments of a real remote tool', function (): void {
    // The same attack one layer up, through the whole tool path: validation,
    // the undeclared-argument carry, and `RemoteTool::handle()`. A test that
    // only ever called the transport directly would miss a layer that decided
    // to be helpful about a key called `url`.
    Http::fake([
        'mcp.example.test/*' => Http::sequence()
            ->push(json_encode([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['tools' => [[
                    'name' => 'lookup_invoice',
                    'description' => 'Look up an invoice.',
                    'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
                ]]],
            ], JSON_THROW_ON_ERROR))
            ->push(mcpBody('INV-42')),
        '*' => Http::response(mcpBody('should never be reached')),
    ]);

    app(Discovery::class)->run($this->server);

    /** @var McpTool $tool */
    $tool = McpTool::query()->firstOrFail();

    $result = (new RemoteTool($tool, $this->server->refresh()))->handle(
        new ToolInput(['arguments' => [
            'query' => 'INV-42',
            'url' => 'http://169.254.169.254/latest/meta-data/',
        ]]),
        $this->toolContext(),
    );

    expect($result->ok)->toBeTrue()
        ->and($result->content)->toBe('INV-42')
        ->and(requestedUrls())->toBe([
            'https://mcp.example.test/rpc',
            'https://mcp.example.test/rpc',
        ]);
});

it('refuses a redirect rather than following it', function (): void {
    // The test that was red. Two assertions and they are different claims:
    // the call fails, AND the second request was never made. A client that
    // followed the redirect and then errored would satisfy the first.
    Http::fake([
        'mcp.example.test/*' => Http::response('', 302, [
            'Location' => 'http://169.254.169.254/latest/meta-data/',
        ]),
        '*' => Http::response(mcpBody('instance credentials')),
    ]);

    try {
        app(HttpTransport::class)->callTool($this->server, 'lookup_invoice', ['query' => 'INV-42']);

        $this->fail('The redirect was followed.');
    } catch (McpDenied $e) {
        expect($e->reason)->toBe('redirected')
            ->and($e->getMessage())->toContain('169.254.169.254');
    }

    expect(requestedUrls())->toBe(['https://mcp.example.test/rpc']);
});

it('refuses a redirect that stays on the same host', function (): void {
    // Not a cross-host check. Guzzle strips the `Authorization` header when a
    // redirect changes host, which makes the same-host case the one that would
    // still carry the credential -- and a path is enough to reach a different
    // application behind the same name.
    Http::fake([
        'mcp.example.test/rpc' => Http::response('', 307, ['Location' => 'https://mcp.example.test/internal']),
        '*' => Http::response(mcpBody('elsewhere')),
    ]);

    try {
        app(HttpTransport::class)->callTool($this->server, 'lookup_invoice', []);

        $this->fail('The redirect was followed.');
    } catch (McpDenied $e) {
        expect($e->reason)->toBe('redirected');
    }

    expect(requestedUrls())->toBe(['https://mcp.example.test/rpc']);
});

it('reads its destination from the server row and from nothing else', function (): void {
    // The structural half. The two tests above prove the client's behaviour on
    // the inputs somebody thought to try; this proves there is only one place
    // a URL can come from at all, which is the claim T6b actually makes.
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Mcp/Transport/HttpTransport.php');

    preg_match_all('/->(get|post|put|patch|delete|send)\(\s*([^,\s)]+)/', $source, $matches);

    expect($matches[2])->not->toBeEmpty()
        ->and(array_unique($matches[2]))->toBe(['$endpoint']);

    // And `$endpoint` is the server row, not an argument. `rpc()` takes the
    // server, the method name and the params; a URL is not among them.
    $rpc = new ReflectionMethod(HttpTransport::class, 'rpc');

    expect(array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        $rpc->getParameters(),
    ))->toBe(['server', 'method', 'params']);
});

it('changes destination only when an operator changes the row', function (): void {
    Http::fake(['*' => Http::response(mcpBody('ok'))]);

    $this->server->update(['endpoint' => 'https://ledger.internal.example.test/mcp']);

    app(HttpTransport::class)->callTool($this->server->refresh(), 'lookup_invoice', []);

    expect(requestedUrls())->toBe(['https://ledger.internal.example.test/mcp']);
});
