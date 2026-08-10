<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Exceptions\McpDenied;
use Pandora\Mcp\Discovery;
use Pandora\Mcp\Enums\ServerHealth;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\McpTool;
use Pandora\Mcp\McpToolApproval;
use Pandora\Mcp\Transport\HttpTransport;
use Pandora\Mcp\Transport\McpTransportContract;
use Pandora\Testing\FakeMcpServer;

/**
 * Phase 6, criterion 16 — discovery writes rows and approves nothing.
 *
 * Run against `FakeMcpServer`, which is a deliverable of this phase rather
 * than a fixture detail: every claim here is a claim about how we behave when
 * a server is hostile, changed or simply odd, and a suite that only ever ran
 * against a well-behaved server has asserted none of them.
 */
beforeEach(function (): void {
    $this->fake = new FakeMcpServer;

    app()->instance(McpTransportContract::class, $this->fake);
    app()->bind(HttpTransport::class, fn () => $this->fake);

    /** @var McpServer $server */
    $server = McpServer::query()->create([
        'name' => 'Ledger',
        'slug' => 'ledger',
        'namespace' => 'ledger',
        'endpoint' => 'https://mcp.example.test/rpc',
    ]);

    $this->server = $server;
    $this->discover = fn (): array => app(Discovery::class)->run($this->server->refresh());
});

it('writes a row for every tool the server offers', function (): void {
    $this->fake->offer('lookup_invoice', 'Look up an invoice by number.')
        ->offer('list_customers', 'List customers.');

    expect(($this->discover)()['discovered'])->toBe(2)
        ->and(McpTool::query()->count())->toBe(2);

    /** @var McpTool $tool */
    $tool = McpTool::query()->where('remote_name', 'lookup_invoice')->firstOrFail();

    expect($tool->namespaced_name)->toBe('ledger-lookup_invoice')
        ->and($tool->description)->toBe('Look up an invoice by number.')
        ->and($tool->schema_hash)->toHaveLength(64);
});

it('approves nothing, for anybody', function (): void {
    $this->fake->offer('lookup_invoice');

    ($this->discover)();

    // The property the whole phase rests on. Anything that both discovers and
    // enables is a remote-controlled permission grant.
    expect(McpToolApproval::query()->count())->toBe(0);

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'mcp.discovery_completed')->firstOrFail();

    // Said in the record, because "we found eleven tools" reads like eleven
    // new capabilities and it is zero.
    expect($entry->metadata['approved'] ?? null)->toBe(0);
});

it('namespaces from the server row rather than from the response', function (): void {
    // A server's own idea of its name is attacker-controlled input being used
    // as an identity, so a `namespace` field in the response reaches nothing.
    $this->fake->offer('lookup_invoice');
    $this->fake->offer('evil');

    ($this->discover)();

    expect(McpTool::query()->pluck('namespaced_name')->all())
        ->each->toStartWith('ledger-');
});

it('skips a tool whose name cannot be published, rather than renaming it', function (string $name): void {
    $this->fake->offer('good_tool');
    $this->fake->offer($name);

    $result = ($this->discover)();

    // Renaming would produce something that no longer matches what the server
    // has to be told on the way back.
    expect($result['discovered'])->toBe(1)
        ->and($result['skipped'])->toBe(1)
        ->and(McpTool::query()->pluck('remote_name')->all())->toBe(['good_tool']);
})->with([
    '../../etc/passwd',
    'ledger-lookup_invoice',
    'has space',
    'has/slash',
    '',
    '1starts_with_digit',
]);

it('bounds a description on the way in', function (): void {
    config()->set('pandora.mcp.client.max_description_length', 100);

    $this->fake->offer('chatty', str_repeat('a', 5000));

    ($this->discover)();

    /** @var McpTool $tool */
    $tool = McpTool::query()->firstOrFail();

    expect(mb_strlen((string) $tool->description))->toBe(100);
});

it('marks the server healthy and stamps when it last looked', function (): void {
    $this->fake->offer('lookup_invoice');

    ($this->discover)();

    $server = $this->server->refresh();

    expect($server->health)->toBe(ServerHealth::Healthy)
        ->and($server->last_discovered_at)->not->toBeNull();
});

it('marks a server unhealthy and says so when it cannot be reached', function (): void {
    $this->fake->unreachable();

    expect(fn () => ($this->discover)())->toThrow(McpDenied::class);

    $server = $this->server->refresh();

    expect($server->health)->toBe(ServerHealth::Unhealthy)
        ->and($server->health_message)->toContain('connection refused')
        ->and(AuditLog::query()->where('action', 'mcp.server_unreachable')->count())->toBe(1);
});

it('marks a withdrawn tool unavailable rather than deleting it', function (): void {
    $this->fake->offer('lookup_invoice')->offer('list_customers');
    ($this->discover)();

    $this->fake->withdraw('list_customers');
    ($this->discover)();

    // The row is what an approval points at and what an audit entry refers to.
    // Deleting it turns "the server withdrew this" into "this never existed".
    expect(McpTool::query()->count())->toBe(2)
        ->and(McpTool::query()->where('remote_name', 'list_customers')->value('available'))->toBeFalsy()
        ->and(McpTool::query()->where('remote_name', 'lookup_invoice')->value('available'))->toBeTruthy();
});

it('is idempotent when nothing changed', function (): void {
    $this->fake->offer('lookup_invoice', 'Look up an invoice.');

    ($this->discover)();
    $first = McpTool::query()->firstOrFail()->schema_hash;

    $result = ($this->discover)();

    expect($result['discovered'])->toBe(0)
        ->and($result['changed'])->toBe(0)
        ->and(McpTool::query()->firstOrFail()->schema_hash)->toBe($first)
        // An approval that cleared itself on every discovery would be an
        // approval nobody could keep.
        ->and(McpTool::query()->firstOrFail()->schema_changed_at)->toBeNull();
});
