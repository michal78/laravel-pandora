<?php

declare(strict_types=1);

use Pandora\Context\ContextBuilder;
use Pandora\Context\ContextRequest;
use Pandora\Messages\Enums\MessageRole;
use Pandora\Messages\Enums\StreamingState;
use Pandora\Messages\Message;
use Pandora\Providers\Data\ChatMessage;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesRuns;

/**
 * A tool loop is only coherent if the next model request contains what the
 * last one asked for and what came back.
 *
 * Phase 1's recency window excluded the current run's own messages and read
 * only user and assistant roles, both of which would silently break the loop:
 * the model would be asked to continue from a request it could not see.
 */
uses(MakesRuns::class);

function replayContext(): array
{
    /** @var Run $run */
    $run = test()->run;

    $built = app(ContextBuilder::class)->build(new ContextRequest(
        run: $run,
        agent: test()->agent,
        session: test()->session,
        tokenBudget: 8000,
    ));

    // Only the conversation itself: the system instructions and environment
    // sections are Phase 1's business, not this test's.
    return array_values(array_filter(
        $built->messages,
        static fn (ChatMessage $m): bool => $m->role !== MessageRole::System,
    ));
}

function storeMessage(array $attributes): Message
{
    static $sequence = 0;

    /** @var Message $message */
    $message = Message::query()->create(array_merge([
        'conversation_id' => test()->conversation->getKey(),
        'session_id' => test()->session->getKey(),
        'run_id' => test()->run->getKey(),
        'sequence' => ++$sequence,
        'streaming_state' => StreamingState::Complete->value,
        'content_format' => 'markdown',
    ], $attributes));

    return $message;
}

beforeEach(function (): void {
    $this->agent = $this->makeAgent();
    $this->conversation = $this->makeConversation($this->agent);
    $this->session = $this->makeSession($this->agent, [
        'conversation_id' => $this->conversation->getKey(),
    ]);
    $this->run = $this->makeRun([
        'agent_id' => $this->agent->getKey(),
        'session_id' => $this->session->getKey(),
        'conversation_id' => $this->conversation->getKey(),
    ]);
});

it('replays a completed tool loop from this same run', function (): void {
    storeMessage(['role' => MessageRole::User->value, 'content' => 'Where is order 1234?']);
    storeMessage([
        'role' => MessageRole::Assistant->value,
        'content' => '',
        'structured' => ['tool_calls' => [
            ['id' => 'call_1', 'name' => 'lookup_order', 'arguments' => ['reference' => '1234']],
        ]],
    ]);
    storeMessage([
        'role' => MessageRole::Tool->value,
        'content' => 'Order 1234 is shipped.',
        'tool_call_id' => 'call_1',
    ]);

    $messages = replayContext();
    $roles = array_map(static fn (ChatMessage $m): string => $m->role->value, $messages);

    expect($roles)->toContain('tool')
        ->and(end($messages)->toolCallId)->toBe('call_1');

    $assistant = array_values(array_filter(
        $messages,
        static fn (ChatMessage $m): bool => $m->requestsTools(),
    ));

    expect($assistant)->toHaveCount(1)
        ->and($assistant[0]->toolCalls[0]->id)->toBe('call_1')
        ->and($assistant[0]->toolCalls[0]->arguments)->toBe(['reference' => '1234']);
});

it('keeps an assistant tool request with no prose, which Phase 1 would have dropped', function (): void {
    storeMessage([
        'role' => MessageRole::Assistant->value,
        'content' => '',
        'structured' => ['tool_calls' => [
            ['id' => 'call_2', 'name' => 'lookup_order', 'arguments' => []],
        ]],
    ]);
    storeMessage(['role' => MessageRole::Tool->value, 'content' => 'Shipped.', 'tool_call_id' => 'call_2']);

    expect(replayContext())->toHaveCount(2);
});

it('drops a tool result whose request fell outside the recency window', function (): void {
    // Both halves or neither: every provider rejects an orphan.
    storeMessage(['role' => MessageRole::Tool->value, 'content' => 'Shipped.', 'tool_call_id' => 'call_gone']);
    storeMessage(['role' => MessageRole::User->value, 'content' => 'And the next one?']);

    $messages = replayContext();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->role)->toBe(MessageRole::User);
});

it('strips tool calls from an assistant turn whose results are missing', function (): void {
    storeMessage([
        'role' => MessageRole::Assistant->value,
        'content' => 'Let me check.',
        'structured' => ['tool_calls' => [
            ['id' => 'call_never_ran', 'name' => 'lookup_order', 'arguments' => []],
        ]],
    ]);

    $messages = replayContext();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->requestsTools())->toBeFalse()
        ->and($messages[0]->content)->toBe('Let me check.');
});

it('drops an unanswered tool request that carried no prose at all', function (): void {
    storeMessage([
        'role' => MessageRole::Assistant->value,
        'content' => '',
        'structured' => ['tool_calls' => [
            ['id' => 'call_never_ran', 'name' => 'lookup_order', 'arguments' => []],
        ]],
    ]);
    storeMessage(['role' => MessageRole::User->value, 'content' => 'Hello?']);

    $messages = replayContext();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->role)->toBe(MessageRole::User);
});

it('still excludes the placeholder this run is about to stream into', function (): void {
    storeMessage(['role' => MessageRole::User->value, 'content' => 'Hello']);
    storeMessage([
        'role' => MessageRole::Assistant->value,
        'content' => '',
        'streaming_state' => StreamingState::Pending->value,
    ]);

    expect(replayContext())->toHaveCount(1);
});

it('never crosses a session boundary, tool messages included', function (): void {
    $other = $this->makeSession($this->agent, ['conversation_id' => $this->conversation->getKey()]);

    Message::query()->create([
        'conversation_id' => $this->conversation->getKey(),
        'session_id' => $other->getKey(),
        'sequence' => 900,
        'role' => MessageRole::Tool->value,
        'content' => 'Another user order: 9999.',
        'tool_call_id' => 'call_other',
        'streaming_state' => StreamingState::Complete->value,
    ]);

    storeMessage(['role' => MessageRole::User->value, 'content' => 'Mine?']);

    $contents = array_map(static fn (ChatMessage $m): string => $m->content, replayContext());

    expect(implode(' ', $contents))->not->toContain('9999');
});
