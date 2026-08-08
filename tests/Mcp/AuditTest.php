<?php

declare(strict_types=1);

use Pandora\Mcp\Discovery;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\McpTool;
use Pandora\Mcp\McpToolApproval;
use Pandora\Mcp\Transport\HttpTransport;
use Pandora\Providers\Data\ToolCall;
use Pandora\Testing\FakeMcpServer;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\ToolCallCoordinator;
use Pandora\Tools\ToolExecution;

/**
 * Phase 6, criterion 25 — a remote call is an ordinary tool execution, with
 * arguments and results redacted like any other.
 *
 * The point is the absence of a special case. A remote call goes through
 * `ToolCallCoordinator`, gets a `pandora_tool_executions` row, and is redacted
 * by the same `Redactor` — because a parallel path for remote calls would be a
 * second place to forget the redaction, and it would be the place handling the
 * least trustworthy data.
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
    $this->tool = $tool;

    McpToolApproval::query()->create([
        'agent_id' => $this->context->agent->getKey(),
        'mcp_tool_id' => $tool->getKey(),
        'approved_schema_hash' => $tool->schema_hash,
        'approved_at' => now(),
    ]);

    $this->dispatch = function (array $arguments): void {
        $coordinator = app(ToolCallCoordinator::class);

        $executions = $coordinator->decide(
            $this->context->run,
            $this->context->agent,
            $this->context->session,
            $this->context->actor,
            [new ToolCall(id: 'call_'.bin2hex(random_bytes(3)), name: 'ledger.lookup_invoice', arguments: $arguments)],
        );

        // Synchronously, so the row is terminal by the time it is read: the
        // point is what gets recorded, not how it got queued.
        $coordinator->dispatch($this->context->run, $executions, $this->context->actor, synchronous: true);
    };
});

it('writes a tool execution row for a remote call', function (): void {
    ($this->dispatch)(['arguments' => ['query' => 'INV-42']]);

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('tool_name', 'ledger.lookup_invoice')->firstOrFail();

    expect($execution->run_id)->toBe($this->context->runId())
        ->and($execution->status->value)->toBe('succeeded');
});

it('redacts a remote call\'s arguments exactly like a local one\'s', function (): void {
    ($this->dispatch)(['arguments' => ['api_key' => 'sk-live-should-not-be-stored', 'query' => 'INV-42']]);

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('tool_name', 'ledger.lookup_invoice')->firstOrFail();

    // The same Redactor, because a second path for remote calls would be the
    // place handling the least trustworthy data and the place most likely to
    // forget.
    $sanitized = json_encode($execution->sanitized_arguments, JSON_THROW_ON_ERROR);

    expect($sanitized)->not->toContain('sk-live-should-not-be-stored');
});

it('records the remote name and the server on the row', function (): void {
    ($this->dispatch)(['arguments' => ['query' => 'INV-42']]);

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('tool_name', 'ledger.lookup_invoice')->firstOrFail();

    $result = json_encode($execution->result, JSON_THROW_ON_ERROR);

    // Which far end answered. A trace that says only "a tool ran" cannot
    // answer the question somebody asks after an incident.
    expect($result)->toContain('ledger')
        ->and($result)->toContain('lookup_invoice');
});

it('records a refused remote call with a reason an operator can read', function (): void {
    // Revoked, so the resolver refuses it at the registry layer.
    McpToolApproval::query()->update(['revoked_at' => now(), 'revoked_reason' => 'operator']);

    ($this->dispatch)(['arguments' => ['query' => 'INV-42']]);

    /** @var ToolExecution|null $execution */
    $execution = ToolExecution::query()->where('tool_name', 'ledger.lookup_invoice')->first();

    // Phase 6's own lesson, applied to the remote half: a refusal that records
    // nothing is a refusal nobody can explain.
    expect($execution)->not->toBeNull()
        ->and($execution->status->value)->toBe('denied')
        ->and((string) $execution->decision_reason)->not->toBe('');
});

it('records a failed remote call as failed rather than as nothing', function (): void {
    $this->fake->unreachable();

    ($this->dispatch)(['arguments' => ['query' => 'INV-42']]);

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('tool_name', 'ledger.lookup_invoice')->firstOrFail();

    expect($execution->status->isTerminal())->toBeTrue()
        ->and($execution->status->value)->not->toBe('succeeded');
});

it('uses one execution path for local and remote alike', function (): void {
    // The structural claim behind the criterion: there is no second
    // coordinator, and nothing outside it writes an execution row.
    $writers = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../src'),
    );

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'ToolExecution::query()->create(')) {
            $writers[] = $file->getBasename('.php');
        }
    }

    expect($writers)->toBe(['ToolCallCoordinator']);
});
