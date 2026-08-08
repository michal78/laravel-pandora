<?php

declare(strict_types=1);

use Pandora\Approvals\Approval;
use Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Channels\ChannelInbox;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesChannels;
use Pandora\Tools\ToolRegistry;

/**
 * Phase 8, criterion 18 — a channel can say an approval is waiting. It cannot
 * carry the decision.
 *
 * This is the feature everybody asks for first and it is deferred on purpose
 * (ADR-0015, decision 10). An approval is a human authorizing a *specific* tool
 * call with the *real* arguments in front of them (T1), consumed exactly once,
 * under the run lock (T14). Reproducing all of that faithfully in a chat
 * surface is a phase of its own, and a button in Slack that approves something
 * the person did not fully see is worse than no button at all.
 *
 * So the pause is announced, and the announcement is all it is.
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    RefundOrderTool::$refunds = [];

    app(ToolRegistry::class)->flush()->register(RefundOrderTool::class);

    $this->account = $this->makeChannelAccount();
    $this->account->agent->forceFill([
        'tool_policy' => ['allow' => ['refund_order']],
        'approval_policy' => ['require' => ['refund_order']],
    ])->save();

    $this->inbox = app(ChannelInbox::class);

    $this->user = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-1', $this->user);
});

it('tells the channel that an approval is waiting', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234',
            'amount_minor' => 4200,
        ])])
        ->willRespondWith('Refunded.');

    $channel = $this->fakeChannel();
    $channel->forget();

    $this->inbox->receive($channel->message('U-1', 'Refund ORD-1234.'));

    $run = Run::query()->firstOrFail();

    expect($run->state)->toBe(RunState::WaitingForApproval)
        ->and($channel->lastText())->toContain('approval')
        ->and(RefundOrderTool::$refunds)->toBe([]);
});

it('does not let a message in the channel resolve the approval', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234',
            'amount_minor' => 4200,
        ])])
        ->willRespondWith('Refunded.');

    $channel = $this->fakeChannel();

    $this->inbox->receive($channel->message('U-1', 'Refund ORD-1234.'));

    $approval = Approval::query()->firstOrFail();

    // Every word somebody might reasonably type expecting it to count.
    foreach (['approve', 'yes', 'approved', 'ok do it'] as $word) {
        $this->fakeProvider()->willRespondWith('I still need approval.');
        $this->inbox->receive($channel->message('U-1', $word));
    }

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(RefundOrderTool::$refunds)->toBe([]);
});
