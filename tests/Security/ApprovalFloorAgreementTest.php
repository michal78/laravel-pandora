<?php

declare(strict_types=1);

use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\Enums\PolicyOutcome;
use Pandora\Tools\Enums\RiskLevel;

/**
 * Phase 9, criterion 1 -- the approval floor says the same thing wherever it is
 * read.
 *
 * Found by removing a mitigation and watching nothing fail.
 * `RiskLevel::requiresApprovalByDefault()` returned `high || critical`,
 * hard-coded; the gatekeeper reads `pandora.approvals.required_for`. Both
 * agreed on the shipped defaults, so the divergence was invisible -- and
 * deleting the enum's rule broke nothing at all, because that method was never
 * on the enforcement path. It had exactly one caller: `pandora:tool:list`,
 * which prints an "Approval" column.
 *
 * So a deployment that narrowed the list to `critical` got a console table
 * saying `required` beside high-risk tools that would run with no human
 * involved. **A control surface disagreeing with the control is worse than
 * having none**, because the person reading it is the person deciding whether
 * the configuration is safe.
 *
 * Both now read the same key. This asserts they keep agreeing under a
 * configuration nobody ships, which is the only place they could drift again.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    $this->registerTools([LookupOrderTool::class, RefundOrderTool::class]);
    $this->agentAllows(['lookup_order', 'refund_order']);
});

it('agrees with the gatekeeper on the shipped default', function (): void {
    expect(RiskLevel::High->requiresApprovalByDefault())->toBeTrue()
        ->and(RiskLevel::Critical->requiresApprovalByDefault())->toBeTrue()
        ->and(RiskLevel::Low->requiresApprovalByDefault())->toBeFalse()
        ->and($this->decide($this->refundCall())->outcome)->toBe(PolicyOutcome::RequireApproval);
});

it('agrees with the gatekeeper when a deployment narrows the floor', function (): void {
    // The configuration that exposed the divergence: high no longer requires a
    // human, and the console must stop saying that it does.
    config()->set('pandora.approvals.required_for', ['critical']);

    expect(RiskLevel::High->requiresApprovalByDefault())->toBeFalse()
        ->and($this->decide($this->refundCall())->outcome)->not->toBe(PolicyOutcome::RequireApproval);
});

it('agrees with the gatekeeper when a deployment widens the floor', function (): void {
    config()->set('pandora.approvals.required_for', ['low', 'medium', 'high', 'critical']);

    expect(RiskLevel::Low->requiresApprovalByDefault())->toBeTrue()
        ->and($this->decide($this->lookupCall())->outcome)->toBe(PolicyOutcome::RequireApproval);
});

it('requires approval for every level the gatekeeper would', function (): void {
    // Exhaustive rather than sampled, over a configuration that turns each
    // level on alone. A new RiskLevel case added without touching either
    // reader is the drift this catches.
    foreach (RiskLevel::cases() as $level) {
        config()->set('pandora.approvals.required_for', [$level->value]);

        foreach (RiskLevel::cases() as $candidate) {
            expect($candidate->requiresApprovalByDefault())
                ->toBe($candidate === $level, "{$candidate->value} under floor [{$level->value}]");
        }
    }
});
