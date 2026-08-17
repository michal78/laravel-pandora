<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Pandora\Agents\AgentRunner;
use Pandora\Realtime\Events\AssistantDeltaReceived;
use Pandora\Realtime\Events\AssistantMessageCompleted;
use Pandora\Realtime\Events\RunStatusChanged;
use Pandora\Runs\Enums\RunState;
use Pandora\Support\Redactor;
use Pandora\Tests\Support\MakesRuns;

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

it('versions and stamps every broadcast payload', function (): void {
    // Renamed 2026-08-17, Phase 9 / T11. This was called "versions and redacts"
    // and asserted nothing whatever about redaction -- `RunStatusChanged`
    // carries no sensitive key, so it passed identically with the redactor
    // deleted from the base class. Verified by deleting it. A test whose NAME
    // claims a mitigation is worse than no test: it is the reason nobody
    // writes the real one.
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

it('puts every payload through the redactor, whatever the event', function (): void {
    // The assertion the old name promised. Rather than hunt for an event that
    // happens to carry a sensitive key -- none of the four do today, which is
    // itself the design working -- this points the redactor at a key the
    // payload definitely has. If `broadcastWith()` stops redacting, `state`
    // comes back unmasked and this fails.
    app()->instance(Redactor::class, new Redactor(['state'], '[masked]'));

    $event = new RunStatusChanged(
        runId: '01ABC', conversationId: null, tenantId: null,
        state: RunState::Running, previousState: RunState::Starting,
    );

    expect($event->broadcastWith()['data']['state'])->toBe('[masked]');
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
