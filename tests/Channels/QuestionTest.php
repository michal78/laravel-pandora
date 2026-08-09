<?php

declare(strict_types=1);

use Pandora\Channels\ChannelInbox;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesChannels;
use Pandora\Tools\BuiltIn\AskUserTool;
use Pandora\Tools\ToolRegistry;

/**
 * Phase 8, finding 13 — an agent that asks the channel a question must actually
 * ask it, and the answer must reach the run that is waiting.
 *
 * Unlike an approval, which a channel may only announce (ADR-0015), a question
 * is the channel's to carry: the person in the channel is the one who can
 * answer it, and no control-center round trip stands between them.
 *
 * Both halves are asserted here because either alone is worse than neither.
 * Delivering the question without resuming asks something no answer can reach;
 * resuming without delivering answers something nobody heard.
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    app(ToolRegistry::class)->flush()->register(AskUserTool::class);

    $this->account = $this->makeChannelAccount();
    $this->account->agent->forceFill([
        'tool_policy' => ['allow' => ['ask_user']],
    ])->save();

    $this->inbox = app(ChannelInbox::class);

    $this->user = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-1', $this->user);
});

it('delivers the question a parked run is waiting on', function (): void {
    $this->fakeProvider()->willRequestTools([
        new ToolCall('call_1', 'ask_user', ['question' => 'Which order do you mean?']),
    ]);

    $channel = $this->fakeChannel();
    $channel->forget();

    $this->inbox->receive($channel->message('U-1', 'Refund my order.'));

    $run = Run::query()->firstOrFail();

    expect($run->state)->toBe(RunState::WaitingForUser)
        ->and($channel->lastText())->toBe('Which order do you mean?');
});

it('answers the waiting run instead of starting a competitor', function (): void {
    $this->fakeProvider()->willRequestTools([
        new ToolCall('call_1', 'ask_user', ['question' => 'Which order do you mean?']),
    ]);

    $channel = $this->fakeChannel();

    $this->inbox->receive($channel->message('U-1', 'Refund my order.'));

    $asked = Run::query()->firstOrFail();

    expect($asked->state)->toBe(RunState::WaitingForUser);

    // The answer arrives as an ordinary channel message.
    $this->fakeProvider()->willRespondWith('Refunded ORD-1234.');

    $this->inbox->receive($channel->message('U-1', 'ORD-1234'));

    expect(Run::query()->count())->toBe(1)
        ->and($asked->fresh()->state)->toBe(RunState::Completed)
        ->and($channel->lastText())->toBe('Refunded ORD-1234.');
});

it('does not treat a run waiting for approval as a question', function (): void {
    // Guards the boundary the fix must not cross: an approval pause is still
    // announced and still unanswerable from the channel, so a message during
    // one starts a new run exactly as it always has (ADR-0015).
    $this->account->agent->forceFill([
        'tool_policy' => ['allow' => ['ask_user']],
        'approval_policy' => ['require_approval' => ['ask_user']],
    ])->save();

    $this->fakeProvider()->willRequestTools([
        new ToolCall('call_1', 'ask_user', ['question' => 'Which order do you mean?']),
    ]);

    $channel = $this->fakeChannel();

    $this->inbox->receive($channel->message('U-1', 'Refund my order.'));

    $first = Run::query()->firstOrFail();

    expect($first->state)->toBe(RunState::WaitingForApproval);

    $this->fakeProvider()->willRespondWith('I still need approval.');

    $this->inbox->receive($channel->message('U-1', 'yes'));

    expect(Run::query()->count())->toBe(2)
        ->and($first->fresh()->state)->toBe(RunState::WaitingForApproval);
});
