<?php

declare(strict_types=1);

use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Pandora\Jobs\StartAgentRun;
use Pandora\Pandora\Messages\Enums\MessageRole;
use Pandora\Pandora\Messages\Enums\StreamingState;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Enums\RunStepType;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Runs\RunStateMachine;
use Pandora\Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/** Acceptance criteria 7, 8, 9, 12 -- the vertical slice. */
it('runs an agent end to end and persists the result', function (): void {
    $this->fakeProvider()->willRespondWith('The order shipped on Tuesday.');

    $agent = $this->makeAgent();
    $conversation = $this->makeConversation($agent);

    $run = app(AgentRunner::class)
        ->agent($agent)
        ->inConversation($conversation)
        ->run('Where is my order?');

    expect($run->state)->toBe(RunState::Completed)
        ->and($run->output)->toBe('The order shipped on Tuesday.')
        ->and($run->iterations)->toBe(1)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->output_tokens)->toBeGreaterThan(0);
});

it('records an ordered, typed trace of the run', function (): void {
    $this->fakeProvider()->willRespondWith('Done.');

    $run = app(AgentRunner::class)
        ->agent($this->makeAgent())
        ->inConversation($this->makeConversation())
        ->run('Hello');

    $types = $run->steps()->get()->map(fn ($s) => $s->type)->all();

    expect($types)->toBe([
        RunStepType::ContextRetrieval,
        RunStepType::ModelRequest,
        RunStepType::ModelResponse,
        RunStepType::FinalResponse,
    ]);

    // Sequence is contiguous from 1.
    expect($run->steps()->pluck('sequence')->all())->toBe([1, 2, 3, 4]);
});

it('persists the user message and the assistant reply', function (): void {
    $this->fakeProvider()->willRespondWith('Hi there.');

    $conversation = $this->makeConversation();

    app(AgentRunner::class)
        ->agent($conversation->agent)
        ->inConversation($conversation)
        ->run('Hello');

    $messages = $conversation->messages()->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe(MessageRole::User)
        ->and($messages[0]->content)->toBe('Hello')
        ->and($messages[1]->role)->toBe(MessageRole::Assistant)
        ->and($messages[1]->content)->toBe('Hi there.')
        // No message is left permanently mid-stream.
        ->and($messages[1]->streaming_state)->toBe(StreamingState::Complete);
});

it('titles the conversation from the first message', function (): void {
    $this->fakeProvider()->willRespondWith('Sure.');

    $conversation = $this->makeConversation();

    app(AgentRunner::class)
        ->agent($conversation->agent)
        ->inConversation($conversation)
        ->run('Please summarise last quarter revenue');

    expect($conversation->refresh()->title)->toBe('Please summarise last quarter revenue');
});

it('queues rather than blocking when dispatched', function (): void {
    Queue::fake();

    $agent = $this->makeAgent();

    $run = app(AgentRunner::class)
        ->agent($agent)
        ->inConversation($this->makeConversation($agent))
        ->dispatch('Hello');

    // The request returns with the run merely queued -- no PHP request is
    // ever held open for an agent run.
    expect($run->state)->toBe(RunState::Queued)
        ->and($run->queued_at)->not->toBeNull();

    Queue::assertPushed(StartAgentRun::class,
        fn (StartAgentRun $job): bool => $job->runId === $run->getKey());
});

it('deduplicates runs sharing an idempotency key', function (): void {
    Queue::fake();

    $agent = $this->makeAgent();
    $runner = app(AgentRunner::class);

    $first = $runner->agent($agent)->idempotencyKey('webhook-42')->dispatch('Go');
    $second = $runner->agent($agent)->idempotencyKey('webhook-42')->dispatch('Go');

    expect($second->getKey())->toBe($first->getKey())
        ->and(Run::query()->count())->toBe(1);
});

it('records the provider failure and fails the run safely', function (): void {
    $this->fakeProvider()->willThrow(
        new ProviderUnavailable('upstream exploded', 'fake'),
    );

    $conversation = $this->makeConversation();

    $run = app(AgentRunner::class)
        ->agent($conversation->agent)
        ->inConversation($conversation)
        ->run('Hello');

    expect($run->state)->toBe(RunState::Failed)
        ->and($run->error_class)->toBe(ProviderUnavailable::class);

    // The user sees a safe message, never the internal one.
    $assistant = $conversation->messages()->where('role', 'assistant')->first();

    expect($assistant->content)->not->toContain('upstream exploded')
        ->and($assistant->streaming_state)->toBe(StreamingState::Failed);

    // But the trace retains the detail for an administrator.
    expect($run->steps()->where('type', RunStepType::Error->value)->exists())->toBeTrue();
});

it('stops when the iteration budget is exhausted', function (): void {
    $this->fakeProvider()->willRespondWith('ok');

    $agent = $this->makeAgent(['max_iterations' => 1]);
    $run = $this->makeRun(['agent_id' => $agent->getKey(), 'iterations' => 1]);

    app(RunStateMachine::class)
        ->transition($run, RunState::Queued);

    dispatch_sync(new StartAgentRun((string) $run->getKey()));

    expect($run->refresh()->state)->toBe(RunState::TimedOut);
});
