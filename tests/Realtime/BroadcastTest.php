<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Realtime\Events\AssistantDeltaReceived;
use Pandora\Pandora\Realtime\Events\AssistantMessageCompleted;
use Pandora\Pandora\Realtime\Events\RunStatusChanged;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/** Acceptance criteria 9 and 10 -- broadcasts and streamed deltas. */
it('broadcasts every state transition through to completion', function (): void {
    Event::fake([RunStatusChanged::class]);
    $this->fakeProvider()->willRespondWith('Done.');

    $conversation = $this->makeConversation();

    app(AgentRunner::class)
        ->agent($conversation->agent)
        ->inConversation($conversation)
        ->run('Hello');

    $states = collect(Event::dispatched(RunStatusChanged::class))
        ->map(fn (array $e): RunState => $e[0]->state);

    expect($states->contains(RunState::Queued))->toBeTrue()
        ->and($states->contains(RunState::Starting))->toBeTrue()
        ->and($states->contains(RunState::Running))->toBeTrue()
        ->and($states->contains(RunState::Completed))->toBeTrue();
});

it('broadcasts streamed deltas with a contiguous sequence', function (): void {
    Event::fake([AssistantDeltaReceived::class, AssistantMessageCompleted::class]);
    $this->fakeProvider()->willRespondWith('One two three four five.');

    $conversation = $this->makeConversation();

    app(AgentRunner::class)
        ->agent($conversation->agent)
        ->inConversation($conversation)
        ->run('Count');

    $deltas = collect(Event::dispatched(AssistantDeltaReceived::class))
        ->map(fn (array $e): AssistantDeltaReceived => $e[0]);

    expect($deltas)->not->toBeEmpty();

    // Monotonic from 1, so a client can detect a gap and refetch.
    expect($deltas->map(fn ($d) => $d->sequence)->all())
        ->toBe(range(1, $deltas->count()));

    // The deltas reassemble into the final message.
    expect($deltas->map(fn ($d) => $d->delta)->implode(''))
        ->toBe('One two three four five.');

    Event::assertDispatched(AssistantMessageCompleted::class);
});

it('versions and redacts every broadcast payload', function (): void {
    $event = new RunStatusChanged(
        runId: '01ABC', conversationId: null, tenantId: null,
        state: RunState::Running, previousState: RunState::Starting,
    );

    $payload = $event->broadcastWith();

    expect($payload)->toHaveKeys(['event', 'version', 'occurred_at', 'correlation_id', 'data'])
        ->and($payload['version'])->toBe(1)
        ->and($payload['event'])->toBe('pandora.run.status_changed')
        ->and($payload['data']['state'])->toBe('running');
});

it('broadcasts only a safe error message, never internal detail', function (): void {
    $event = new RunStatusChanged(
        runId: '01ABC', conversationId: null, tenantId: null,
        state: RunState::Failed, previousState: RunState::Running,
        safeErrorMessage: 'The AI provider is temporarily unavailable.',
    );

    $json = json_encode($event->broadcastWith());

    expect($json)->toContain('temporarily unavailable')
        ->and($json)->not->toContain('Exception')
        ->and($json)->not->toContain('/src/');
});

it('suppresses broadcasting entirely when realtime is disabled', function (): void {
    config()->set('pandora.realtime.enabled', false);

    $event = new RunStatusChanged(
        runId: '01ABC', conversationId: null, tenantId: null,
        state: RunState::Running, previousState: null,
    );

    expect($event->broadcastWhen())->toBeFalse();
});

it('targets the run, conversation and tenant channels', function (): void {
    $event = new RunStatusChanged(
        runId: 'run-1', conversationId: 'conv-1', tenantId: 'acme',
        state: RunState::Running, previousState: null,
    );

    $names = collect($event->broadcastOn())->map(fn ($c) => $c->name)->all();

    expect($names)->toContain('private-pandora.run.run-1')
        ->and($names)->toContain('private-pandora.conversation.conv-1')
        ->and($names)->toContain('private-pandora.tenant.acme');
});
