<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Agents\AgentRegistry;
use Pandora\Agents\AgentRunner;
use Pandora\Jobs\ResumeRunWithUserReply;
use Pandora\Messages\Enums\StreamingState;
use Pandora\Messages\Message;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Runs\RunStateMachine;
use Pandora\Tests\Fixtures\EchoAgent;
use Pandora\Tests\Fixtures\TestUser;
use Pandora\Tests\Support\MakesRuns;
use Pandora\UI\Livewire\Chat;

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

/*
|--------------------------------------------------------------------------
| The conversation owns its agent
|--------------------------------------------------------------------------
|
| Found by the Phase 2 host walkthrough, 2026-08-07. `mount()` seeded the
| picker from `availableAgents()->first()` -- ordered by name -- and never
| looked at the conversation. Opening or merely reloading a conversation
| repointed it at whichever agent sorted first, and every later message ran
| with that agent's instructions, tools, model, autonomy and budgets while
| `conversations.agent_id` went on naming the original.
|
| Every test below needs a SECOND agent sorting before 'Echo'. The suite had
| exactly one agent, which is why `->first()` was never observably wrong.
*/

it('shows the conversation\'s own agent, not whichever sorts first', function (): void {
    $first = $this->makeAgent(['name' => 'Aardvark', 'slug' => 'aardvark']);
    $echo = app(AgentRegistry::class)->get('echo');

    $conversation = $this->makeConversation($echo, [
        'created_by_type' => $this->user::class,
        'created_by_id' => (string) $this->user->getKey(),
    ]);

    expect($first->name)->toBeLessThan($echo->name);

    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->assertSet('agentSlug', 'echo');
});

it('renders the agent as a fact once a conversation exists', function (): void {
    $this->makeAgent(['name' => 'Aardvark', 'slug' => 'aardvark']);
    $echo = app(AgentRegistry::class)->get('echo');

    $conversation = $this->makeConversation($echo, [
        'created_by_type' => $this->user::class,
        'created_by_id' => (string) $this->user->getKey(),
    ]);

    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->assertSee('Echo')
        ->assertDontSeeHtml('id="pd-agent-select"');

    // A new conversation still gets a real choice.
    Livewire::test(Chat::class)->assertSeeHtml('id="pd-agent-select"');
});

it('runs as the conversation\'s agent even when the picker is forged', function (): void {
    $intruder = $this->makeAgent(['name' => 'Aardvark', 'slug' => 'aardvark']);
    $echo = app(AgentRegistry::class)->get('echo');

    $conversation = $this->makeConversation($echo, [
        'created_by_type' => $this->user::class,
        'created_by_id' => (string) $this->user->getKey(),
    ]);

    // `agentSlug` is a public Livewire property: the `disabled` attribute on
    // the markup is a courtesy to the operator, not a control. This is the
    // request that attribute cannot stop.
    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->set('agentSlug', 'aardvark')
        ->set('composer', 'Who is answering me?')
        ->call('send');

    $run = Run::query()->where('conversation_id', $conversation->getKey())->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->agent_id)->toBe($echo->getKey())
        ->and($run->agent_id)->not->toBe($intruder->getKey());

    expect($conversation->fresh()->agent_id)->toBe($echo->getKey());
});

/*
|--------------------------------------------------------------------------
| Empty assistant placeholders
|--------------------------------------------------------------------------
|
| Found by the Phase 2 host walkthrough, 2026-08-07. The placeholder exists so
| a reload mid-request has something to render; rendering it while still empty
| produces a blank bubble, and a run parked at an approval never fills it.
*/

it('does not render an assistant message that is still empty', function (): void {
    $echo = app(AgentRegistry::class)->get('echo');
    $conversation = $this->makeConversation($echo, [
        'created_by_type' => $this->user::class,
        'created_by_id' => (string) $this->user->getKey(),
    ]);
    $session = $this->makeSession($echo);
    $run = $this->makeRun([
        'agent_id' => $echo->getKey(),
        'conversation_id' => $conversation->getKey(),
        'session_id' => $session->getKey(),
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->getKey(),
        'session_id' => $session->getKey(),
        'run_id' => $run->getKey(),
        'role' => 'assistant',
        'type' => 'text',
        'content' => '',
        'sequence' => 1,
        'streaming_state' => StreamingState::Streaming->value,
    ]);

    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->assertOk()
        ->assertDontSeeHtml('pd-msg-body');
});

it('still renders an assistant message the moment it has content', function (): void {
    $echo = app(AgentRegistry::class)->get('echo');
    $conversation = $this->makeConversation($echo, [
        'created_by_type' => $this->user::class,
        'created_by_id' => (string) $this->user->getKey(),
    ]);
    $session = $this->makeSession($echo);
    $run = $this->makeRun([
        'agent_id' => $echo->getKey(),
        'conversation_id' => $conversation->getKey(),
        'session_id' => $session->getKey(),
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->getKey(),
        'session_id' => $session->getKey(),
        'run_id' => $run->getKey(),
        'role' => 'assistant',
        'type' => 'text',
        'content' => 'Half a sen',
        'sequence' => 1,
        'streaming_state' => StreamingState::Streaming->value,
    ]);

    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->assertSee('Half a sen')
        ->assertSeeHtml('pd-msg-body');
});
