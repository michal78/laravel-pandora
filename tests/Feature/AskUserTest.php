<?php

declare(strict_types=1);

use Pandora\Pandora\Exceptions\InvalidRunTransition;
use Pandora\Pandora\Facades\Pandora;
use Pandora\Pandora\Messages\Enums\MessageRole;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\Tools\BuiltIn\AskUserTool;
use Pandora\Pandora\Tools\ToolInput;

/**
 * Phase 2 acceptance criterion 27 — an agent that is unsure stops and asks.
 *
 * The same pause as an approval, for the same reason: no job in flight, no
 * cost, and a question sitting where the person will actually see it.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    $this->registerTools([AskUserTool::class]);
    $this->agentAllows(['ask_user']);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'ask_user', [
            'question' => 'Which of your two orders do you mean?',
        ])])
        ->willRespondWith('Thanks — order ORD-2 has shipped.');
});

it('parks the run at waiting_for_user, holding no job', function (): void {
    $run = $this->runToolAgent('Where is my order?');

    expect($run->state)->toBe(RunState::WaitingForUser)
        ->and($run->state->isWaiting())->toBeTrue()
        ->and($run->owner_token)->toBeNull();
});

it('puts the question in the conversation', function (): void {
    $run = $this->runToolAgent('Where is my order?');

    /** @var Message $question */
    $question = Message::query()
        ->where('run_id', $run->getKey())
        ->where('role', MessageRole::Assistant->value)
        ->orderByDesc('sequence')
        ->firstOrFail();

    expect($question->content)->toBe('Which of your two orders do you mean?')
        ->and($question->metadata['awaiting_answer'])->toBeTrue();
});

it('resumes when the user answers', function (): void {
    $run = $this->runToolAgent('Where is my order?');

    Pandora::reply($run, 'The second one.', synchronous: true);

    /** @var Run $resumed */
    $resumed = Run::query()->findOrFail($run->getKey());

    expect($resumed->state)->toBe(RunState::Completed)
        ->and($resumed->output)->toBe('Thanks — order ORD-2 has shipped.');
});

it('shows the model the answer through the ordinary context pipeline', function (): void {
    $run = $this->runToolAgent('Where is my order?');

    Pandora::reply($run, 'The second one.', synchronous: true);

    $requests = $this->fakeProvider()->receivedRequests();
    $lastRequest = end($requests);
    $contents = array_map(static fn ($m): string => $m->content, $lastRequest->messages);

    expect(implode("\n", $contents))->toContain('The second one.');
});

it('records the answer as a message from the user', function (): void {
    $run = $this->runToolAgent('Where is my order?');

    Pandora::reply($run, 'The second one.', synchronous: true);

    // A user message belongs to the conversation, not to the run: the person
    // answering is not part of the run's own trace.
    expect(Message::query()
        ->where('conversation_id', $run->conversation_id)
        ->where('role', MessageRole::User->value)
        ->where('content', 'The second one.')
        ->exists())->toBeTrue();
});

it('refuses a reply to a run that never asked anything', function (): void {
    $this->fakeProvider()->reset()->willRespondWith('No question here.');

    $run = $this->runToolAgent('Hello');

    expect(fn () => Pandora::reply($run, 'Answer to nothing.'))
        ->toThrow(InvalidRunTransition::class, 'not waiting for an answer');
});

it('refuses to ask when nobody is there to answer', function (): void {
    // A run with no conversation -- an automation, a webhook -- would park
    // until it expired, so the tool declines rather than stranding it.
    $tool = new AskUserTool;

    $context = $this->toolContext();

    expect($tool->authorize(
        new ToolInput(['question' => 'Anyone there?']),
        $context,
    ))->toBeFalse();
});
