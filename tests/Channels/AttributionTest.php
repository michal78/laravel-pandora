<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Channels\ChannelInbox;
use Pandora\Runs\Enums\TriggerType;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesChannels;

/**
 * Phase 8, criterion 19 — a channel run can be explained afterwards.
 *
 * The question this answers is the one asked six months later, by somebody who
 * was not there: *who caused this, through what, on whose behalf?* A run that
 * can only say "an agent did it" is a run nobody can hold anyone to, and a
 * channel is precisely where that ambiguity would creep in — the agent is the
 * visible actor, the person is one indirection away, and the account is another.
 *
 * All three are on the row.
 */
uses(MakesChannels::class);

it('names the actor, the account and the participant', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $account = $this->makeChannelAccount();
    $identity = $this->makeIdentity($account, 'U-1', $user, ['display_name' => 'Jo']);

    $this->fakeProvider()->willRespondWith('Done.');

    app(ChannelInbox::class)->receive($this->fakeChannel()->message('U-1', 'Do the thing.'));

    $run = Run::query()->firstOrFail();

    expect($run->trigger_type)->toBe(TriggerType::Channel)
        // The ACTOR is the person, not the agent and not the account.
        ->and($run->actor_type)->toBe($user::class)
        ->and($run->actor_id)->toBe((string) $user->getKey());

    $channel = $run->metadata['context']['channel'] ?? [];

    expect($channel['key'] ?? null)->toBe($account->channel)
        ->and($channel['account'] ?? null)->toBe($account->slug)
        ->and($channel['participant_external_id'] ?? null)->toBe('U-1');

    $delivery = $account->deliveries()->where('direction', 'inbound')->firstOrFail();

    expect($delivery->run_id)->toBe((string) $run->getKey())
        ->and($delivery->identity_id)->toBe((string) $identity->getKey());
});

it('audits the message against the account and the run', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $account = $this->makeChannelAccount();
    $this->makeIdentity($account, 'U-1', $user);

    $this->fakeProvider()->willRespondWith('Done.');

    app(ChannelInbox::class)->receive($this->fakeChannel()->message('U-1', 'Do the thing.'));

    $log = AuditLog::query()->where('action', 'channel.message_received')->firstOrFail();

    expect($log->target_id)->toBe((string) $account->getKey())
        ->and($log->run_id)->toBe((string) Run::query()->firstOrFail()->getKey());
});
