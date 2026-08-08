<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pandora\Audit\AuditLog;
use Pandora\Mcp\Server\McpServerController;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolRegistry;
use Pandora\Tools\ToolResult;

/**
 * Phase 6, criterion 27 — an authenticated call is authorized against the
 * actor behind the token, and a valid token for an actor lacking the ability
 * is refused.
 *
 * The two questions are separate and both are asked (ADR-0014):
 *
 *   - the allowlist decides what EXISTS on this server;
 *   - the tool's own authorize() decides who may call it.
 *
 * Skipping the second makes the token a superuser, because the only thing it
 * would then prove is that somebody at some point was issued one.
 */

/** A tool that asks the one question this criterion is about. */
final class GatedProbeTool extends Tool
{
    public function name(): string
    {
        return 'gated_probe';
    }

    public function description(): string
    {
        return 'Answers only for an actor holding the ability.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        $user = $context->user();

        return $user !== null && Gate::forUser($user)->allows('probe.call');
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        return ToolResult::success('probed');
    }
}

beforeEach(function (): void {
    config()->set('pandora.mcp.server.enabled', true);
    config()->set('pandora.mcp.server.middleware', ['web', 'auth']);
    config()->set('pandora.mcp.server.exposed_tools', ['gated_probe']);

    app(ToolRegistry::class)->flush()->register(new GatedProbeTool);

    // Registered here because the route is built at boot from configuration,
    // and this test needs the enabled shape rather than the shipped one.
    app('router')->post('/pandora/mcp', McpServerController::class)
        ->middleware(['web', 'auth'])
        ->name('pandora.mcp.server.test');

    $this->rpc = fn (string $method, array $params = []) => $this->postJson('/pandora/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
    ]);
});

it('refuses an unauthenticated call before it reaches a tool', function (): void {
    // The host's own middleware, not ours. Pandora ships no authentication and
    // this route is behind whatever the deployment put in front of it.
    ($this->rpc)('tools/call', ['name' => 'gated_probe'])->assertStatus(401);
});

it('allows an actor that holds the ability', function (): void {
    Gate::define('probe.call', static fn (): bool => true);

    $this->actingAsUser();

    $response = ($this->rpc)('tools/call', ['name' => 'gated_probe', 'arguments' => []]);

    expect($response->json('result.content.0.text'))->toBe('probed')
        ->and($response->json('result.isError'))->toBeFalse();
});

it('refuses a valid token whose actor lacks the ability', function (): void {
    // The criterion, stated exactly. Authenticated, exposed, and still no.
    Gate::define('probe.call', static fn (): bool => false);

    $this->actingAsUser();

    $response = ($this->rpc)('tools/call', ['name' => 'gated_probe', 'arguments' => []]);

    expect($response->json('error.message'))->toBe('Not authorized to call this tool.')
        ->and($response->json('result'))->toBeNull();
});

it('records the refusal at warning', function (): void {
    Gate::define('probe.call', static fn (): bool => false);

    $this->actingAsUser();
    ($this->rpc)('tools/call', ['name' => 'gated_probe', 'arguments' => []]);

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'mcp.exposure_denied')->firstOrFail();

    expect($entry->severity)->toBe('warning')
        ->and($entry->metadata['reason'] ?? null)->toBe('not authorized');
});

it('lists an exposed tool without promising the caller may call it', function (): void {
    Gate::define('probe.call', static fn (): bool => false);

    $this->actingAsUser();

    $response = ($this->rpc)('tools/list');

    // Listing is not narrowed per actor: a listing that were would leak the
    // shape of our permission model to anybody holding a token. What may be
    // CALLED is decided per call.
    expect($response->json('result.tools.0.name'))->toBe('gated_probe');
});

it('records a successful call', function (): void {
    Gate::define('probe.call', static fn (): bool => true);

    $this->actingAsUser();
    ($this->rpc)('tools/call', ['name' => 'gated_probe', 'arguments' => []]);

    expect(AuditLog::query()->where('action', 'mcp.server_call')->count())->toBe(1);
});

it('serves nothing but tools/list and tools/call', function (): void {
    Gate::define('probe.call', static fn (): bool => true);

    $this->actingAsUser();

    foreach (['resources/list', 'prompts/list', 'sampling/createMessage', 'initialize'] as $method) {
        expect(($this->rpc)($method)->json('error.code'))->toBe(-32601);
    }
});

it('validates arguments before authorizing, and refuses bad ones plainly', function (): void {
    Gate::define('probe.call', static fn (): bool => true);

    $this->actingAsUser();

    // Not a crash, and not a 500: a malformed call from a protocol client is
    // an ordinary bad request.
    $response = ($this->rpc)('tools/call', ['name' => 'gated_probe', 'arguments' => ['nope' => 1]]);

    expect($response->status())->toBe(200)
        ->and($response->json('result.content.0.text'))->toBe('probed');
});
