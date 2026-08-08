<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pandora\Mcp\Server\McpServerController;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolRegistry;
use Pandora\Tools\ToolResult;

/**
 * A tool that reads a tenant-scoped Pandora row by id.
 *
 * Purpose-built, because the built-in that looks closest -- `inspect_run_status`
 * -- reports on the run it is INSIDE, and this surface has no run. That is a
 * real constraint on what may be exposed over MCP, and it is now a clean
 * refusal rather than a stack trace.
 */
final class TenantScopedLookupTool extends Tool
{
    public function name(): string
    {
        return 'lookup_run';
    }

    public function description(): string
    {
        return 'Look up a run by id.';
    }

    public function rules(): array
    {
        return ['run_id' => 'required|string'];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return true;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        // Through the model, so the tenant global scope applies -- which is
        // the entire mechanism this criterion is about.
        $run = Run::query()->find($input->requiredString('run_id'));

        return $run === null
            ? ToolResult::failure('No such run was found.')
            : ToolResult::success((string) $run->input);
    }
}

/**
 * Phase 6, criterion 28 — an MCP server call cannot reach another tenant's
 * data, whatever it asks for.
 *
 * The shape of the guarantee matters more than the assertion. Tenancy on this
 * surface is **ambient**: it comes from the host's own resolution of the
 * request — a domain, a token, a middleware — and never from anything the
 * caller sent. There is no tenant parameter in the protocol payload and there
 * is deliberately nowhere to put one, because a tenant id a caller can name is
 * a tenant id a caller can change.
 *
 * The tool used here takes an id and looks a row up, which is exactly the
 * shape that leaks across a boundary when the scope is applied loosely.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    config()->set('pandora.mcp.server.enabled', true);
    config()->set('pandora.mcp.server.exposed_tools', ['lookup_run']);

    app(ToolRegistry::class)->flush()->register(new TenantScopedLookupTool);

    app('router')->post('/pandora/mcp', McpServerController::class)
        ->middleware(['web', 'auth'])
        ->name('pandora.mcp.server.test');

    Gate::before(static fn (): bool => true);

    $this->rpc = fn (string $method, array $params = []) => $this->postJson('/pandora/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
    ]);

    $this->acmeRun = inTenant('acme', fn (): Run => $this->makeRun([
        'tenant_id' => 'acme',
        'state' => 'completed',
        'input' => 'acme only',
    ]));
});

it('does not reach another tenant\'s run through an exposed tool', function (): void {
    $this->actingAsUser();

    // Asked for by id, from outside the tenant that owns it.
    $response = inTenant('globex', fn () => ($this->rpc)('tools/call', [
        'name' => 'lookup_run',
        'arguments' => ['run_id' => $this->acmeRun->getKey()],
    ]));

    $text = (string) $response->json('result.content.0.text');

    expect($text)->not->toContain('acme only')
        // Not found rather than forbidden: the difference confirms the id
        // exists, which is the fact worth withholding.
        // Not found rather than forbidden.
        ->and($text)->toContain('No such run');
});

it('reaches its own tenant\'s run', function (): void {
    $this->actingAsUser();

    $response = inTenant('acme', fn () => ($this->rpc)('tools/call', [
        'name' => 'lookup_run',
        'arguments' => ['run_id' => $this->acmeRun->getKey()],
    ]));

    expect($response->json('result.content.0.text'))->toBe('acme only')
        ->and($response->json('result.isError'))->toBeFalse();
});

it('takes no tenant from the caller, because there is nowhere to put one', function (): void {
    $this->actingAsUser();

    // A tenant a caller can name is a tenant a caller can change. The payload
    // below is ignored entirely, which is the point.
    $response = inTenant('globex', fn () => ($this->rpc)('tools/call', [
        'name' => 'lookup_run',
        'arguments' => ['run_id' => $this->acmeRun->getKey()],
        'tenant_id' => 'acme',
        'tenant' => 'acme',
    ]));

    expect((string) $response->json('result.content.0.text'))->not->toContain('acme only');
});

it('reads the tenant from the host rather than from the protocol', function (): void {
    // Structural: the controller asks TenantManager, and there is no path from
    // the request payload to a tenant id anywhere in it.
    $source = (string) file_get_contents(__DIR__.'/../../src/Mcp/Server/McpServerController.php');

    expect($source)->toContain('$this->tenants->currentId()')
        ->and($source)->not->toContain("params['tenant")
        ->and($source)->not->toContain("payload['tenant");
});
