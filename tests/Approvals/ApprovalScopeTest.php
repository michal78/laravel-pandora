<?php

declare(strict_types=1);

use Pandora\Pandora\Approvals\Approval;
use Pandora\Pandora\Approvals\ApprovalManager;
use Pandora\Pandora\Approvals\Enums\ApprovalScope;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Tests\Fixtures\TestUser;
use Pandora\Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Pandora\Tests\Support\MakesTools;

/**
 * Phase 2 acceptance criterion 18 — how far a decision reaches.
 *
 * Each scope trades a little safety for a little convenience, so each one has
 * to be exactly as wide as it claims and no wider.
 */
uses(MakesTools::class);

function pauseRefundRun(string $callId = 'call_1'): Approval
{
    test()->fakeProvider()
        ->reset()
        ->willRequestTools([new ToolCall($callId, 'refund_order', [
            'reference' => 'ORD-'.$callId,
            'amount_minor' => 500,
        ])])
        ->willRespondWith('Done.');

    $run = test()->runToolAgent('Refund it.');

    /** @var Approval $approval */
    $approval = Approval::query()->where('run_id', $run->getKey())->firstOrFail();

    return $approval;
}

beforeEach(function (): void {
    RefundOrderTool::$refunds = [];
    $this->registerTools([RefundOrderTool::class]);
    $this->agentAllows(['refund_order']);
    $this->actor = ActorContext::forUser($this->toolUser());
});

it('covers nothing beyond the call it was made about, at once scope', function (): void {
    $approval = pauseRefundRun();

    app(ApprovalManager::class)->approve($approval, $this->actor, ApprovalScope::Once, authorize: false);

    // A second, separate run pauses again: nothing was carried forward.
    pauseRefundRun('call_2');

    expect(Approval::query()->pending()->count())->toBe(1);
});

it('covers the rest of the run, at run scope', function (): void {
    $approval = pauseRefundRun();

    app(ApprovalManager::class)->approve($approval, $this->actor, ApprovalScope::Run, authorize: false);

    expect(app(ApprovalManager::class)->coveringApproval(
        $approval->run,
        'refund_order',
        $this->actor,
    ))->not->toBeNull();
});

it('does not leak a run-scoped decision into another run', function (): void {
    $approval = pauseRefundRun();
    app(ApprovalManager::class)->approve($approval, $this->actor, ApprovalScope::Run, authorize: false);

    $other = pauseRefundRun('call_2');

    expect($other->run_id)->not->toBe($approval->run_id)
        ->and(app(ApprovalManager::class)->coveringApproval($other->run, 'refund_order', $this->actor))
        ->toBeNull();
});

it('covers the same actor across runs, at remembered scope', function (): void {
    $approval = pauseRefundRun();
    app(ApprovalManager::class)->approve($approval, $this->actor, ApprovalScope::Remembered, authorize: false);

    // A later run by the same person: the decision is already made, so this
    // one never pauses at all.
    $later = $this->makeRun(['agent_id' => $this->agent()->getKey()]);

    expect(app(ApprovalManager::class)->coveringApproval($later, 'refund_order', $this->actor))
        ->not->toBeNull();
});

it('does not extend a remembered decision to a different actor', function (): void {
    // The property that makes `remembered` survivable: it remembers a PERSON's
    // choice, not a global setting.
    $approval = pauseRefundRun();
    app(ApprovalManager::class)->approve($approval, $this->actor, ApprovalScope::Remembered, authorize: false);

    $later = $this->makeRun(['agent_id' => $this->agent()->getKey()]);

    $stranger = ActorContext::forUser(TestUser::create([
        'name' => 'Someone Else', 'email' => 'else@example.test', 'password' => 'secret',
    ]));

    expect(app(ApprovalManager::class)->coveringApproval($later, 'refund_order', $stranger))
        ->toBeNull();
});

it('does not extend a remembered decision to a different tool', function (): void {
    $approval = pauseRefundRun();
    app(ApprovalManager::class)->approve($approval, $this->actor, ApprovalScope::Remembered, authorize: false);

    $later = $this->makeRun(['agent_id' => $this->agent()->getKey()]);

    expect(app(ApprovalManager::class)->coveringApproval($later, 'delete_everything', $this->actor))
        ->toBeNull();
});

it('honours a deployment that switches remembered approvals off', function (): void {
    $approval = pauseRefundRun();
    app(ApprovalManager::class)->approve($approval, $this->actor, ApprovalScope::Remembered, authorize: false);

    config()->set('pandora.approvals.allow_remembered', false);

    $later = $this->makeRun(['agent_id' => $this->agent()->getKey()]);

    expect(app(ApprovalManager::class)->coveringApproval($later, 'refund_order', $this->actor))
        ->toBeNull();
});

it('never lets a denial become a standing permission', function (): void {
    $approval = pauseRefundRun();
    app(ApprovalManager::class)->deny($approval, $this->actor, authorize: false);

    $later = $this->makeRun(['agent_id' => $this->agent()->getKey()]);

    expect(app(ApprovalManager::class)->coveringApproval($later, 'refund_order', $this->actor))
        ->toBeNull();
});
