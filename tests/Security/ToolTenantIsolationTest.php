<?php

declare(strict_types=1);

use Pandora\Providers\Data\ToolCall;
use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Tools\ToolContext;

/**
 * Phase 2 acceptance criterion 7 — layer 3.
 *
 * A tenant restriction is not the same thing as an agent allowlist: agents are
 * configured by whoever runs the deployment, tenants are the customers of it.
 * One tenant reaching a tool another tenant paid for is a defect even when the
 * agent configuration is identical.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    $this->registerTools([LookupOrderTool::class, RefundOrderTool::class]);
    $this->agentAllows(['lookup_order', 'refund_order']);
    $this->agentApprovalPolicy(['auto_approve' => ['refund_order']]);
});

it('leaves a tenant absent from the configuration unrestricted', function (): void {
    config()->set('pandora.tools.tenants', ['other-tenant' => ['allow' => []]]);

    expect($this->decide($this->lookupCall(), $this->toolContext(tenantId: 'acme'))->isAllowed())
        ->toBeTrue();
});

it('restricts a configured tenant to the tools it names', function (): void {
    config()->set('pandora.tools.tenants', ['acme' => ['allow' => ['lookup_order']]]);

    $context = fn (): ToolContext => $this->toolContext(tenantId: 'acme');

    expect($this->decide($this->lookupCall(), $context())->isAllowed())->toBeTrue()
        ->and($this->decide($this->refundCall(), $context())->isDenied())->toBeTrue()
        ->and($this->decide($this->refundCall(), $context())->layer)->toBe(AuthorizationLayer::Tenant);
});

it('denies a tool a tenant denylist names, even when the allowlist grants it', function (): void {
    config()->set('pandora.tools.tenants', [
        'acme' => ['allow' => ['group:billing'], 'deny' => ['refund_order']],
    ]);

    expect($this->decide($this->refundCall(), $this->toolContext(tenantId: 'acme'))->layer)
        ->toBe(AuthorizationLayer::Tenant);
});

it('applies one tenant restriction to that tenant only', function (): void {
    // The property that matters: identical agents, different answers.
    config()->set('pandora.tools.tenants', ['acme' => ['allow' => ['lookup_order']]]);

    expect($this->decide($this->refundCall(), $this->toolContext(tenantId: 'acme'))->isDenied())
        ->toBeTrue()
        ->and($this->decide($this->refundCall(), $this->toolContext(tenantId: 'globex'))->isDenied())
        ->toBeFalse();
});

it('checks the tenant before validating anything the model sent', function (): void {
    // A refused tenant learns nothing about the tool's interface, not even
    // through an argument validation message.
    config()->set('pandora.tools.tenants', ['acme' => ['allow' => []]]);

    $decision = $this->decide(
        new ToolCall('call_1', 'lookup_order', ['reference' => 'x']),
        $this->toolContext(tenantId: 'acme'),
    );

    expect($decision->layer)->toBe(AuthorizationLayer::Tenant)
        ->and($decision->reason)->not->toContain('reference');
});
