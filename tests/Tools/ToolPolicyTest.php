<?php

declare(strict_types=1);

use Pandora\Pandora\Contracts\ToolPolicy;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Pandora\Tools\Enums\PolicyOutcome;
use Pandora\Pandora\Tools\PolicyDecision;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolGatekeeper;
use Pandora\Pandora\Tools\ToolInput;

/**
 * Phase 2 acceptance criterion 8 — all five policy outcomes take effect.
 */
uses(MakesTools::class);

function bindPolicy(Closure $evaluate): void
{
    app()->instance(ToolPolicy::class, new class($evaluate) implements ToolPolicy
    {
        public function __construct(private readonly Closure $evaluate) {}

        public function evaluate(Tool $tool, ToolInput $input, ToolContext $context): PolicyDecision
        {
            return ($this->evaluate)($tool, $input, $context);
        }
    });

    // The gatekeeper resolves its policy once, at construction.
    app()->forgetInstance(ToolGatekeeper::class);
}

beforeEach(function (): void {
    $this->registerTools([LookupOrderTool::class, RefundOrderTool::class]);
    $this->agentAllows(['lookup_order', 'refund_order']);
});

it('allows when the policy raises no objection', function (): void {
    bindPolicy(fn (): PolicyDecision => PolicyDecision::allow());

    expect($this->decide($this->lookupCall())->outcome)->toBe(PolicyOutcome::Allow);
});

it('denies when the policy refuses, and passes the reason to the model', function (): void {
    bindPolicy(fn (): PolicyDecision => PolicyDecision::deny('Refunds are frozen during month end.'));

    $decision = $this->decide($this->refundCall());

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->layer)->toBe(AuthorizationLayer::Policy)
        ->and($decision->modelMessage())->toContain('month end');
});

it('pauses for approval when the policy asks for one', function (): void {
    bindPolicy(fn (): PolicyDecision => PolicyDecision::requireApproval('Over the desk limit.'));

    $decision = $this->decide($this->lookupCall());

    expect($decision->outcome)->toBe(PolicyOutcome::RequireApproval)
        ->and($decision->pausesRun())->toBeTrue()
        ->and($decision->reason)->toBe('Over the desk limit.');
});

it('pauses for the user themselves when the policy asks for confirmation', function (): void {
    bindPolicy(fn (): PolicyDecision => PolicyDecision::requireConfirmation('Just checking.'));

    $decision = $this->decide($this->lookupCall());

    expect($decision->outcome)->toBe(PolicyOutcome::RequireConfirmation)
        ->and($decision->pausesRun())->toBeTrue()
        ->and($decision->modelMessage())->toContain('confirm');
});

it('applies modified arguments and records the diff', function (): void {
    // The canonical case: clamp a refund to the desk limit.
    bindPolicy(fn (Tool $tool, ToolInput $input): PolicyDecision => PolicyDecision::modifyArguments(
        [...$input->toArray(), 'amount_minor' => 1000],
        'Clamped to the £10 desk limit.',
    ));

    $decision = $this->decide($this->refundCall(amountMinor: 90000));

    expect($decision->wasModified())->toBeTrue()
        ->and($decision->arguments()['amount_minor'])->toBe(1000)
        ->and($decision->diff?->toArray())->toBe([
            ['field' => 'amount_minor', 'from' => 90000, 'to' => 1000],
        ])
        ->and($decision->diff?->summary())->toBe('amount_minor: 90000 -> 1000');
});

it('tells an approver that the arguments they are approving were changed', function (): void {
    // A modified call that also needs approval must say so on the card; the
    // diff alone, discovered at approval time, is not telling anybody.
    bindPolicy(fn (Tool $tool, ToolInput $input): PolicyDecision => PolicyDecision::modifyArguments(
        [...$input->toArray(), 'amount_minor' => 1000],
        'Clamped to the desk limit.',
    ));

    $trace = $this->decide($this->refundCall(amountMinor: 90000))->toTrace();

    expect($trace)->toHaveKey('argument_diff')
        ->and($trace['outcome'])->toBe('require_approval')
        ->and($trace['reason'])->toContain('High risk')
        ->and($trace['reason'])->toContain('Clamped to the desk limit.');
});

it('never modifies arguments silently', function (): void {
    bindPolicy(fn (Tool $tool, ToolInput $input): PolicyDecision => PolicyDecision::modifyArguments(
        [...$input->toArray(), 'reference' => 'ORD-CLAMPED'],
        'Forced onto the tenant order.',
    ));

    $trace = $this->decide($this->lookupCall())->toTrace();

    expect($trace)->toHaveKey('argument_diff')
        ->and($trace['outcome'])->toBe('modify_arguments')
        ->and($trace['reason'])->toBe('Forced onto the tenant order.');
});

it('re-validates modified arguments so a policy cannot bypass the tool rules', function (): void {
    bindPolicy(fn (Tool $tool, ToolInput $input): PolicyDecision => PolicyDecision::modifyArguments(
        [...$input->toArray(), 'amount_minor' => 999999999],
        'Generous.',
    ));

    $decision = $this->decide($this->refundCall());

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->reason)->toContain('rewrote the arguments');
});

it('keeps the risk floor when a policy merely raises no objection', function (): void {
    bindPolicy(fn (): PolicyDecision => PolicyDecision::allow());

    expect($this->decide($this->refundCall())->outcome)->toBe(PolicyOutcome::RequireApproval);
});

it('lowers the risk floor only when a policy says so explicitly', function (): void {
    bindPolicy(fn (): PolicyDecision => PolicyDecision::allowWithoutApproval('Desk-approved standing rule.'));

    expect($this->decide($this->refundCall())->outcome)->toBe(PolicyOutcome::Allow);
});

it('cannot use a policy allow to overrule the tool own authorization', function (): void {
    // Layer 4 saying yes never skips layer 5.
    bindPolicy(fn (): PolicyDecision => PolicyDecision::allowWithoutApproval('Fine by me.'));

    $context = $this->toolContext(actor: ActorContext::system('automation'));

    $decision = $this->decide($this->refundCall(), $context);

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->layer)->toBe(AuthorizationLayer::Tool);
});

it('reads the default policy from the agent approval_policy column', function (): void {
    $this->agentApprovalPolicy(['deny' => ['refund_order']]);

    $decision = $this->decide($this->refundCall());

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->reason)->toContain('refuse');
});

it('pre-approves a tool the agent policy auto-approves', function (): void {
    $this->agentApprovalPolicy(['auto_approve' => ['refund_order']]);

    expect($this->decide($this->refundCall())->outcome)->toBe(PolicyOutcome::Allow);
});

it('requires approval for a group the agent policy names', function (): void {
    $this->agentApprovalPolicy(['require_approval' => ['group:general']]);

    $decision = $this->decide($this->lookupCall());

    expect($decision->outcome)->toBe(PolicyOutcome::RequireApproval)
        ->and($decision->reason)->toContain('requires approval');
});
