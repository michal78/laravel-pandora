<?php

declare(strict_types=1);

use Pandora\Providers\Data\ToolCall;
use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Tools\Enums\PolicyOutcome;

/**
 * Phase 2 acceptance criteria 5, 6 and 24 — layers 1 and 2, and the order the
 * layers run in.
 *
 * Each layer is tested vetoing on its own, because a layer that only works
 * when the others also object is not a layer.
 */
uses(MakesTools::class);

it('denies a tool the registry has never heard of', function (): void {
    $decision = $this->decide(new ToolCall('call_1', 'shell_exec', ['cmd' => 'rm -rf /']));

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->layer)->toBe(AuthorizationLayer::Registry)
        ->and($decision->reason)->toContain('shell_exec');
});

it('denies a registered tool the agent was never granted', function (): void {
    $this->registerTools([LookupOrderTool::class]);
    $this->agentAllows([]);

    $decision = $this->decide($this->lookupCall());

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->layer)->toBe(AuthorizationLayer::Agent);
});

it('allows a tool the agent was granted', function (): void {
    $this->registerTools([LookupOrderTool::class]);
    $this->agentAllows(['lookup_order']);

    expect($this->decide($this->lookupCall())->isAllowed())->toBeTrue();
});

it('grants a whole group with one reference', function (): void {
    $this->registerTools([RefundOrderTool::class]);
    $this->agentAllows(['group:billing']);

    expect($this->decide($this->refundCall())->layer)->not->toBe(AuthorizationLayer::Agent);
});

it('lets a denylist carve one tool out of a granted group', function (): void {
    $this->registerTools([RefundOrderTool::class]);
    $this->agentAllows(['group:billing'], deny: ['refund_order']);

    $decision = $this->decide($this->refundCall());

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->layer)->toBe(AuthorizationLayer::Agent);
});

it('resolves an agent grant written as an alias or a versioned name', function (string $reference): void {
    $this->registerTools([RefundOrderTool::class]);
    $this->agentAllows([$reference]);

    expect($this->decide($this->refundCall())->layer)->not->toBe(AuthorizationLayer::Agent);
})->with(['refund_order', 'issue_refund', 'refund_order@1.0']);

it('treats an empty allowlist as no tools, never as all tools', function (): void {
    $this->registerTools([LookupOrderTool::class, RefundOrderTool::class]);
    $this->agentAllows([]);

    expect($this->decide($this->lookupCall())->isDenied())->toBeTrue()
        ->and($this->decide($this->refundCall())->isDenied())->toBeTrue();
});

it('honours the always_available list without an agent grant', function (): void {
    $this->registerTools([LookupOrderTool::class]);
    $this->agentAllows([]);
    config()->set('pandora.tools.always_available', ['lookup_order']);

    expect($this->decide($this->lookupCall())->isAllowed())->toBeTrue();
});

it('lets an agent denylist override always_available', function (): void {
    $this->registerTools([LookupOrderTool::class]);
    $this->agentAllows([], deny: ['lookup_order']);
    config()->set('pandora.tools.always_available', ['lookup_order']);

    expect($this->decide($this->lookupCall())->isDenied())->toBeTrue();
});

it('rejects invalid arguments before any policy or tool code runs', function (): void {
    $this->registerTools([LookupOrderTool::class]);
    $this->agentAllows(['lookup_order']);

    $decision = $this->decide(new ToolCall('call_1', 'lookup_order', ['reference' => 'x']));

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->layer)->toBe(AuthorizationLayer::Validation)
        ->and($decision->modelMessage())->toContain('reference');
});

it('strips an argument the model invented from what the tool will receive', function (): void {
    $this->registerTools([LookupOrderTool::class]);
    $this->agentAllows(['lookup_order']);

    $decision = $this->decide(new ToolCall('call_1', 'lookup_order', [
        'reference' => 'ORD-1234',
        'bypass_authorization' => true,
    ]));

    expect($decision->isAllowed())->toBeTrue()
        ->and($decision->arguments())->toBe(['reference' => 'ORD-1234']);
});

it('pauses a high-risk tool for approval even when every other layer allows', function (): void {
    $this->registerTools([RefundOrderTool::class]);
    $this->agentAllows(['refund_order']);

    $decision = $this->decide($this->refundCall());

    expect($decision->outcome)->toBe(PolicyOutcome::RequireApproval)
        ->and($decision->pausesRun())->toBeTrue()
        ->and($decision->isAllowed())->toBeFalse()
        ->and($decision->reason)->toContain('High risk');
});

it('records which layer decided, so a trace explains itself', function (): void {
    $this->registerTools([LookupOrderTool::class]);
    $this->agentAllows([]);

    $trace = $this->decide($this->lookupCall())->toTrace();

    expect($trace['decided_by'])->toBe('agent')
        ->and($trace['outcome'])->toBe('deny')
        ->and($trace['tool'])->toBe('lookup_order')
        // Arguments are recorded once on the execution row, redacted, not
        // duplicated into every step.
        ->and($trace)->not->toHaveKey('arguments');
});

it('tells the model why, specifically enough for it to stop trying', function (): void {
    $this->registerTools([LookupOrderTool::class]);
    $this->agentAllows([]);

    expect($this->decide($this->lookupCall())->modelMessage())
        ->toContain('lookup_order')
        ->toContain('not permitted');
});
