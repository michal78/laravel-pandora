<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Pandora\Agents\AgentRegistry;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Jobs\ResumeRunWithUserReply;
use Pandora\Pandora\Messages\Enums\StreamingState;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Runs\RunStateMachine;
use Pandora\Pandora\Tests\Fixtures\EchoAgent;
use Pandora\Pandora\Tests\Fixtures\TestUser;
use Pandora\Pandora\Tests\Support\MakesRuns;
use Pandora\Pandora\UI\Livewire\Chat;

uses(MakesRuns::class);

beforeEach(function (): void {
    app(AgentRegistry::class)->define(EchoAgent::class)->syncAll(force: true);
    $this->user = $this->actingAsUser();
});

/** Acceptance criteria 6, 8, 11, 13 and guarantee 22. */
it('renders for an authorized user', function (): void {
    Livewire::test(Chat::class)->assertOk();
});

it('denies an unauthorized user', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);

    Livewire::test(Chat::class)->assertForbidden();
});

it('denies a guest', function (): void {
    auth()->logout();

    Livewire::test(Chat::class)->assertForbidden();
});

it('creates a conversation and queues a run when a message is sent', function (): void {
    Queue::fake();

    $component = Livewire::test(Chat::class)
        ->set('composer', 'Where is my order?')
        ->call('send');

    $component->assertOk();

    expect($component->get('conversationId'))->not->toBe('');

    $run = Run::query()->first();

    // The request returned with the run merely queued.
    expect($run)->not->toBeNull()
        ->and($run->state)->toBe(RunState::Queued);

    // The user's message is already persisted and visible.
    expect(Message::query()->where('role', 'user')->first()?->content)
        ->toBe('Where is my order?');
});

it('reconstructs correct state from the database with every broadcast dropped', function (): void {
    // Guarantee 22 / criterion 11: the reload test. No events are delivered
    // to the component at all -- it must still render the right thing.
    $this->fakeProvider()->willRespondWith('Your order shipped on Tuesday.');

    $conversation = $this->makeConversation(null, [
        'created_by_type' => $this->user::class,
        'created_by_id' => (string) $this->user->getKey(),
    ]);

    app(AgentRunner::class)
        ->agent($conversation->agent)
        ->forUser($this->user)
        ->inConversation($conversation)
        ->run('Where is my order?');

    // A completely fresh component -- exactly what a page reload produces.
    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->assertOk()
        ->assertSee('Where is my order?')
        ->assertSee('Your order shipped on Tuesday.');
});

it('renders a partially streamed message after a mid-stream reload', function (): void {
    $conversation = $this->makeConversation(null, [
        'created_by_type' => $this->user::class,
        'created_by_id' => (string) $this->user->getKey(),
    ]);

    $session = $this->makeSession($conversation->agent);
    $run = $this->makeRun([
        'agent_id' => $conversation->agent_id,
        'conversation_id' => $conversation->getKey(),
        'session_id' => $session->getKey(),
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->getKey(),
        'session_id' => $session->getKey(),
        'run_id' => $run->getKey(),
        'role' => 'assistant', 'type' => 'text', 'sequence' => 1,
        'content' => 'Partial answer so f',
        'streaming_state' => StreamingState::Streaming->value,
    ]);

    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->assertOk()
        // Nothing is lost: the partial content is on the page.
        ->assertSee('Partial answer so f');
});

it('refuses to load another user\'s conversation', function (): void {
    $other = TestUser::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']);

    $conversation = $this->makeConversation(null, [
        'created_by_type' => $other::class,
        'created_by_id' => (string) $other->getKey(),
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->getKey(),
        'role' => 'user', 'type' => 'text', 'sequence' => 1,
        'content' => 'SOMEONE ELSES PRIVATE MESSAGE',
        'streaming_state' => 'complete',
    ]);

    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->assertOk()
        ->assertDontSee('SOMEONE ELSES PRIVATE MESSAGE');
});

it('lets the owner cancel an active run', function (): void {
    $conversation = $this->makeConversation(null, [
        'created_by_type' => $this->user::class,
        'created_by_id' => (string) $this->user->getKey(),
    ]);

    $run = $this->makeRun([
        'agent_id' => $conversation->agent_id,
        'conversation_id' => $conversation->getKey(),
        'actor_type' => $this->user::class,
        'actor_id' => (string) $this->user->getKey(),
    ]);

    app(RunStateMachine::class)->transition($run, RunState::Queued);

    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->call('cancelRun', (string) $run->getKey());

    expect($run->refresh()->state)->toBe(RunState::Cancelled);
});

it('refuses to cancel a run belonging to another user', function (): void {
    $other = TestUser::create(['name' => 'Other', 'email' => 'o2@example.test', 'password' => 'x']);

    $run = $this->makeRun([
        'actor_type' => $other::class,
        'actor_id' => (string) $other->getKey(),
    ]);

    app(RunStateMachine::class)->transition($run, RunState::Queued);

    Livewire::test(Chat::class)->call('cancelRun', (string) $run->getKey());

    expect($run->refresh()->state)->toBe(RunState::Queued);
});

it('works correctly with realtime disabled', function (): void {
    config()->set('pandora.realtime.enabled', false);

    $this->fakeProvider()->willRespondWith('Polling still works.');

    $conversation = $this->makeConversation(null, [
        'created_by_type' => $this->user::class,
        'created_by_id' => (string) $this->user->getKey(),
    ]);

    app(AgentRunner::class)
        ->agent($conversation->agent)
        ->forUser($this->user)
        ->inConversation($conversation)
        ->run('Hello');

    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->assertOk()
        ->assertSee('Polling still works.');
});

/**
 * A parked run is owed an answer, not a competitor.
 *
 * `ask_user` leaves the run at `waiting_for_user` holding no job, waiting for
 * `Pandora::reply()` to resume it. If the composer starts a fresh run instead,
 * the parked one is never resumed and never reaches a terminal state, so it
 * remains the conversation's active run -- and the header reports "Waiting for
 * you" over a conversation that has since moved on.
 */
it('answers a run that asked a question instead of starting a rival run', function (): void {
    Queue::fake();

    $agent = app(AgentRegistry::class)->get('echo');
    $conversation = $this->makeConversation($agent, [
        'created_by_type' => $this->user::class,
        'created_by_id' => (string) $this->user->getKey(),
    ]);
    $session = $this->makeSession($agent);

    $waiting = $this->makeRun([
        'agent_id' => $agent->getKey(),
        'session_id' => $session->getKey(),
        'conversation_id' => $conversation->getKey(),
        'state' => RunState::WaitingForUser->value,
        'actor_type' => $this->user::class,
        'actor_id' => (string) $this->user->getKey(),
    ]);

    Livewire::test(Chat::class)
        ->set('conversationId', (string) $conversation->getKey())
        ->set('composer', 'Michal')
        ->call('send')
        ->assertOk();

    // No second run: the answer belongs to the run that asked.
    expect(Run::query()->count())->toBe(1);

    Queue::assertPushed(ResumeRunWithUserReply::class,
        static fn ($job): bool => $job->runId === (string) $waiting->getKey());

    // And the answer is an ordinary message, reaching the model by the one path.
    expect(Message::query()->where('content', 'Michal')->exists())->toBeTrue();
});
