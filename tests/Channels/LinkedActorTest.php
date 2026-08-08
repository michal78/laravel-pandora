<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pandora\Channels\ChannelInbox;
use Pandora\Channels\Data\InboundOutcome;
use Pandora\Conversations\Session;
use Pandora\Runs\Enums\TriggerType;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\Tools\GatedTool;
use Pandora\Tests\Support\MakesChannels;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\ToolExecution;
use Pandora\Tools\ToolRegistry;

/**
 * Phase 8, criterion 7 — a linked identity acts as its user, and only as far as
 * that user could act alone.
 *
 * This is where the linking flow earns its friction. Once an identity is
 * linked, the run it produces authorizes tools against the *host user*
 * (ADR-0007), which means an agent reachable from Slack is exactly as
 * privileged as the person messaging it — no more, and no less because it
 * arrived through a channel.
 *
 * The second test is the one that matters: the same message, the same agent,
 * the same tool, a different linked user, and the tool is refused. If that ever
 * passed, a channel would be a way to borrow somebody else's permissions.
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    app(ToolRegistry::class)->flush()->register(GatedTool::class);

    $this->account = $this->makeChannelAccount();
    $this->account->agent->forceFill(['tool_policy' => ['allow' => ['gated_action']]])->save();

    $this->inbox = app(ChannelInbox::class);
});

it('runs as the linked user', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $this->makeIdentity($this->account, 'U-1', $user);

    $this->fakeProvider()->willRespondWith('Here you go.');

    $result = $this->inbox->receive($this->fakeChannel()->message('U-1', 'What is the status?'));

    expect($result->outcome)->toBe(InboundOutcome::Accepted);

    $run = Run::query()->firstOrFail();

    expect($run->actor_type)->toBe($user::class)
        ->and($run->actor_id)->toBe((string) $user->getKey())
        ->and($run->trigger_type)->toBe(TriggerType::Channel);
});

it('allows a tool the linked user is allowed', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    Gate::define('manage-orders', fn ($actor): bool => (string) $actor->getKey() === (string) $user->getKey());

    $this->makeIdentity($this->account, 'U-1', $user);

    $this->fakeProvider()
        ->willRequestTools([GatedTool::call()])
        ->willRespondWith('Done.');

    $this->inbox->receive($this->fakeChannel()->message('U-1', 'Act on ORD-1234'));

    expect(ToolExecution::query()->firstOrFail()->status)->toBe(ToolExecutionStatus::Succeeded);
});

it('refuses a tool the linked user is not allowed, even though the agent permits it', function (): void {
    $permitted = $this->actingAsUser();
    $other = $this->actingAsUser();
    app('auth')->logout();

    // The gate names one user. The agent's allowlist names the tool. The agent
    // is not what decides.
    Gate::define('manage-orders', fn ($actor): bool => (string) $actor->getKey() === (string) $permitted->getKey());

    $this->makeIdentity($this->account, 'U-other', $other);

    $this->fakeProvider()
        ->willRequestTools([GatedTool::call()])
        ->willRespondWith('I could not do that.');

    $this->inbox->receive($this->fakeChannel()->message('U-other', 'Act on ORD-1234'));

    expect(ToolExecution::query()->firstOrFail()->status)->toBe(ToolExecutionStatus::Denied);
});

it('keys the session on the channel and the participant', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $identity = $this->makeIdentity($this->account, 'U-1', $user);

    $this->fakeProvider()->willRespondWith('Fine.');

    $this->inbox->receive($this->fakeChannel()->message('U-1', 'hello'));

    $session = Session::query()->firstOrFail();

    expect($session->channel)->toBe($this->fakeChannel()->key())
        ->and($session->channel_participant_id)->toBe($identity->fresh()->participantKey())
        ->and($session->actor_id)->toBe((string) $user->getKey());
});
