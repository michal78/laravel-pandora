<?php

declare(strict_types=1);

use Pandora\Mcp\Discovery;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\McpTool;
use Pandora\Mcp\McpToolApproval;
use Pandora\Mcp\Transport\HttpTransport;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\Data\ToolDefinition;
use Pandora\Testing\FakeMcpServer;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Tools\ToolGatekeeper;

/**
 * Phase 6, criteria 17 and 18 — an unapproved remote tool is not offered and
 * is refused if called, and approval is per agent.
 *
 * "Not offered" is the stronger half and the one worth being precise about. An
 * unapproved tool is not advertised-and-then-refused; it is absent from what
 * the model is shown, so the model never forms the call. The refusal exists
 * for the call that arrives anyway — a replayed transcript, a model that
 * invented the name, a tool approved yesterday and revoked since.
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

    $this->approveFor = function (string $agentId): McpToolApproval {
        /** @var McpTool $tool */
        $tool = McpTool::query()->firstOrFail();

        /** @var McpToolApproval $approval */
        $approval = McpToolApproval::query()->create([
            'agent_id' => $agentId,
            'mcp_tool_id' => $tool->getKey(),
            'approved_schema_hash' => $tool->schema_hash,
            'approved_at' => now(),
        ]);

        return $approval;
    };

    $this->advertised = fn (): array => array_map(
        static fn (ToolDefinition $definition): string => $definition->name,
        app(ToolGatekeeper::class)->advertise($this->context),
    );

    $this->call = fn (array $arguments = []) => app(ToolGatekeeper::class)->evaluate(
        new ToolCall(id: 'call_1', name: 'ledger-lookup_invoice', arguments: $arguments),
        $this->context,
    );
});

it('does not offer an unapproved remote tool', function (): void {
    expect(($this->advertised)())->not->toContain('ledger-lookup_invoice');
});

it('refuses an unapproved remote tool that is called anyway', function (): void {
    $decision = ($this->call)();

    expect($decision->isAllowed())->toBeFalse()
        // Refused at the registry layer, so the model learns that no such tool
        // is available to it -- not that it exists, not that somebody else may
        // call it, and not why.
        ->and($decision->layer)->toBe(AuthorizationLayer::Registry);
});

it('offers it once this agent has approved it', function (): void {
    ($this->approveFor)($this->context->agent->getKey());

    expect(($this->advertised)())->toContain('ledger-lookup_invoice');
});

it('allows the call once approved', function (): void {
    ($this->approveFor)($this->context->agent->getKey());

    expect(($this->call)(['arguments' => ['query' => 'INV-42']])->isAllowed())->toBeTrue();
});

/**
 * Criterion 18. Two agents on one server are two different blast radii.
 */
it('grants nothing to another agent', function (): void {
    ($this->approveFor)('01JOTHERAGENTOTHERAGENTXXY');

    expect(($this->advertised)())->not->toContain('ledger-lookup_invoice')
        ->and(($this->call)()->isAllowed())->toBeFalse();
});

it('stops offering it the moment approval is revoked', function (): void {
    $approval = ($this->approveFor)($this->context->agent->getKey());

    expect(($this->advertised)())->toContain('ledger-lookup_invoice');

    $approval->update(['revoked_at' => now(), 'revoked_reason' => 'operator']);

    expect(($this->advertised)())->not->toContain('ledger-lookup_invoice')
        ->and(($this->call)()->isAllowed())->toBeFalse();
});

it('stops offering it when the tool changed under the approval', function (): void {
    ($this->approveFor)($this->context->agent->getKey());

    // Same parameters, new sentence. Discovery clears the approval; this
    // asserts the call path agrees rather than relying on that having run.
    McpTool::query()->firstOrFail()->update(['schema_hash' => str_repeat('a', 64)]);

    expect(($this->advertised)())->not->toContain('ledger-lookup_invoice')
        ->and(($this->call)()->isAllowed())->toBeFalse();
});

it('stops offering it when the server goes unhealthy', function (): void {
    ($this->approveFor)($this->context->agent->getKey());

    $this->server->update(['health' => 'unhealthy']);

    // Unavailable rather than slow: a run that waits on a server known to be
    // down has converted a clear failure into a timeout.
    expect(($this->advertised)())->not->toContain('ledger-lookup_invoice')
        ->and(($this->call)()->isAllowed())->toBeFalse();
});

it('stops offering it when the server withdrew it', function (): void {
    ($this->approveFor)($this->context->agent->getKey());

    $this->fake->withdraw('lookup_invoice');
    app(Discovery::class)->run($this->server->refresh());

    expect(($this->advertised)())->not->toContain('ledger-lookup_invoice')
        ->and(($this->call)()->isAllowed())->toBeFalse();
});

it('offers nothing remote at all while the client is disabled', function (): void {
    ($this->approveFor)($this->context->agent->getKey());

    config()->set('pandora.mcp.client.enabled', false);

    expect(($this->advertised)())->not->toContain('ledger-lookup_invoice')
        ->and(($this->call)()->isAllowed())->toBeFalse();
});

it('does not need the tool in the agent allowlist as well', function (): void {
    // Approval IS the grant for a remote tool. Requiring it in two places
    // means two places to drift, and the drift always fails open in one of
    // them.
    ($this->approveFor)($this->context->agent->getKey());

    expect($this->context->agent->allowedTools())->not->toContain('ledger-lookup_invoice')
        ->and(($this->call)(['arguments' => []])->isAllowed())->toBeTrue();
});
