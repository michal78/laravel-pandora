<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Mcp\Discovery;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\McpTool;
use Pandora\Mcp\McpToolApproval;
use Pandora\Mcp\RemoteTool;
use Pandora\Mcp\Transport\HttpTransport;
use Pandora\Providers\Data\ToolCall;
use Pandora\Testing\FakeMcpServer;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\ToolGatekeeper;
use Pandora\Tools\ToolInput;

/**
 * Phase 6, criteria 23 and 25 — a remote call that fails is a tool error, and
 * it is recorded.
 *
 * The shape that matters: a server that hangs costs **one tool call**, not one
 * worker. Nothing here is allowed to throw past the tool loop, because a
 * transport exception escaping into a run turns somebody else's outage into
 * our failed run.
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
    $this->fake->offer('lookup_invoice', 'Look up an invoice.')->returns('lookup_invoice', 'INV-42');

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

    $this->remote = new RemoteTool($tool, $server->refresh());
    $this->invoke = fn (array $arguments = []) => $this->remote->handle(
        new ToolInput(['arguments' => $arguments]),
        $this->context,
    );
});

it('returns the remote result on the happy path', function (): void {
    $result = ($this->invoke)(['query' => 'INV-42']);

    expect($result->ok)->toBeTrue()
        ->and($result->content)->toBe('INV-42');
});

it('sends the remote name, not the namespaced one', function (): void {
    ($this->invoke)(['query' => 'INV-42']);

    // The namespace is ours. Sending it back would name a tool the server has
    // never heard of.
    expect($this->fake->calls)->toContain([
        'method' => 'tools/call',
        'name' => 'lookup_invoice',
        'arguments' => ['query' => 'INV-42'],
    ]);
});

it('carries the arguments the MODEL sent, not only ones we declared', function (): void {
    // The path every real call takes and no test took: a model forms a call
    // against the schema the SERVER advertised, so its arguments are top-level
    // `invoice_id`, not a key called `arguments`. Validation keeps only what
    // `rules()` declared, so building the input by hand -- as the tests above
    // do -- skips the only step that can lose them.
    //
    // Losing them is silent. The tool succeeds, the run completes, the server
    // is asked for nothing in particular and answers about nothing in
    // particular.
    $decision = app(ToolGatekeeper::class)->evaluate(
        new ToolCall(id: 'c1', name: 'ledger-lookup_invoice', arguments: ['invoice_id' => 'INV-42']),
        $this->context,
    );

    expect($decision->isAllowed())->toBeTrue();

    $this->remote->handle($decision->input, $this->context);

    expect($this->fake->calls)->toContain([
        'method' => 'tools/call',
        'name' => 'lookup_invoice',
        'arguments' => ['invoice_id' => 'INV-42'],
    ]);
});

it('fails as a tool error when the server hangs', function (): void {
    $this->fake->hangs();

    $result = ($this->invoke)();

    // Not an exception. One tool call, not one worker.
    expect($result->ok)->toBeFalse()
        ->and($result->content)->toBe('That tool is not available right now.');
});

it('fails as a tool error when the server is unreachable', function (): void {
    $this->fake->unreachable();

    expect(($this->invoke)()->ok)->toBeFalse();
});

it('refuses an oversized response rather than returning it', function (): void {
    config()->set('pandora.mcp.client.max_response_bytes', 1024);
    $this->fake->returnsOversized(1048576);

    $result = ($this->invoke)();

    expect($result->ok)->toBeFalse()
        ->and($result->content)->toContain('too much data');
});

it('fails as a tool error on a JSON-RPC error', function (): void {
    $this->fake->failsWith('invoice service is down');

    expect(($this->invoke)()->ok)->toBeFalse();
});

it('tells the model less than it tells the operator', function (): void {
    $this->fake->failsWith('connect to 10.0.0.7:5432 failed: password authentication failed for user "ledger"');

    $result = ($this->invoke)();

    // A refusal is a fact about our infrastructure being handed to something
    // that may be relaying an attacker's instructions.
    expect($result->content)->not->toContain('10.0.0.7')
        ->and($result->content)->not->toContain('password');

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'mcp.call_failed')->firstOrFail();

    expect($entry->metadata['tool'] ?? null)->toBe('ledger-lookup_invoice')
        ->and($entry->metadata['server'] ?? null)->toBe('ledger');
});

it('records a failed call against the run', function (): void {
    $this->fake->unreachable();

    ($this->invoke)();

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'mcp.call_failed')->firstOrFail();

    expect($entry->run_id)->toBe($this->context->runId())
        // An unreachable server is a warning; a tool that merely said no is not.
        ->and($entry->severity)->toBe('warning');
});

it('bounds what a remote result may put in front of the model', function (): void {
    $this->fake->returns('lookup_invoice', str_repeat('x', 50000));

    $result = ($this->invoke)();

    expect(mb_strlen($result->content))->toBe(20000);
});

it('carries the server and remote name on the execution record', function (): void {
    $result = ($this->invoke)(['query' => 'INV-42']);

    // Criterion 25: a remote call is an ordinary tool execution, and the row
    // says which far end answered it.
    expect($result->data['server'] ?? null)->toBe('ledger')
        ->and($result->data['remote_name'] ?? null)->toBe('lookup_invoice');
});
