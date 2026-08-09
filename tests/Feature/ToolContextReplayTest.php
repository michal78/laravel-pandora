<?php

declare(strict_types=1);

use Pandora\Context\ContextBuilder;
use Pandora\Context\ContextRequest;
use Pandora\Context\TranscriptNormaliser;
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

/**
 * Finding 9, first half: the answer exists but is not adjacent.
 *
 * Captured live on 2026-08-09 -- an ordinary `recall` call, and one second
 * between the request and somebody typing at a bot that looked idle:
 *
 *   18:24:07  assistant  tool_calls  call_5Qoy...
 *   18:24:08  user       "Hello?"
 *   18:24:10  tool       result for call_5Qoy...
 *
 * Four consecutive runs then died at the provider, and the conversation never
 * recovered. Reordering alone is enough here: nothing is missing.
 */
it('moves a tool result interleaved with a user message back to its request', function (): void {
    storeMessage(['role' => MessageRole::User->value, 'content' => 'What did I say about the plant?']);
    storeMessage([
        'role' => MessageRole::Assistant->value,
        'content' => '',
        'structured' => ['tool_calls' => [
            ['id' => 'call_5Qoy', 'name' => 'recall', 'arguments' => ['query' => 'plant']],
        ]],
    ]);
    storeMessage(['role' => MessageRole::User->value, 'content' => 'Hello?']);
    storeMessage([
        'role' => MessageRole::Tool->value,
        'content' => 'It is called Kevin.',
        'tool_call_id' => 'call_5Qoy',
    ]);

    $messages = replayContext();

    $shape = array_map(static fn (ChatMessage $m): string => $m->role->value, $messages);

    expect($shape)->toBe(['user', 'assistant', 'tool', 'user'])
        ->and($messages[1]->toolCalls[0]->id)->toBe('call_5Qoy')
        ->and($messages[2]->toolCallId)->toBe('call_5Qoy')
        // Nothing is lost -- the interleaved message is moved, not dropped.
        ->and($messages[3]->content)->toBe('Hello?');
});

it('groups each assistant turn with its own results when two runs interleave', function (): void {
    storeMessage([
        'role' => MessageRole::Assistant->value,
        'content' => '',
        'structured' => ['tool_calls' => [['id' => 'call_a', 'name' => 'recall', 'arguments' => []]]],
    ]);
    storeMessage([
        'role' => MessageRole::Assistant->value,
        'content' => '',
        'structured' => ['tool_calls' => [['id' => 'call_b', 'name' => 'recall', 'arguments' => []]]],
    ]);
    storeMessage(['role' => MessageRole::Tool->value, 'content' => 'A.', 'tool_call_id' => 'call_a']);
    storeMessage(['role' => MessageRole::Tool->value, 'content' => 'B.', 'tool_call_id' => 'call_b']);

    $messages = replayContext();

    expect(array_map(static fn (ChatMessage $m): string => $m->content, $messages))
        ->toBe(['', 'A.', '', 'B.'])
        ->and($messages[1]->toolCallId)->toBe('call_a')
        ->and($messages[3]->toolCallId)->toBe('call_b');
});

/**
 * Finding 9, second half: the answer does not exist yet.
 *
 * A run parked on an approval holds an open tool call for as long as the
 * approver takes. Reordering cannot repair a message that was never written,
 * so one is synthesised -- truthfully, because the call has not failed.
 */
it('synthesises a placeholder result for a call still awaiting approval', function (): void {
    storeMessage([
        'role' => MessageRole::Assistant->value,
        'content' => 'Let me check.',
        'structured' => ['tool_calls' => [
            ['id' => 'call_awaiting', 'name' => 'send_notification', 'arguments' => []],
        ]],
    ]);
    storeMessage(['role' => MessageRole::User->value, 'content' => 'yes']);

    $messages = replayContext();

    expect(array_map(static fn (ChatMessage $m): string => $m->role->value, $messages))
        ->toBe(['assistant', 'tool', 'user'])
        // The request survives intact: an approval that later resolves must
        // find the call it belongs to.
        ->and($messages[0]->toolCalls[0]->id)->toBe('call_awaiting')
        ->and($messages[1]->toolCallId)->toBe('call_awaiting')
        ->and($messages[1]->content)->toBe(TranscriptNormaliser::PENDING_RESULT);
});

it('answers every call of a parallel batch, not just the ones that came back', function (): void {
    storeMessage([
        'role' => MessageRole::Assistant->value,
        'content' => '',
        'structured' => ['tool_calls' => [
            ['id' => 'call_done', 'name' => 'recall', 'arguments' => []],
            ['id' => 'call_open', 'name' => 'send_notification', 'arguments' => []],
        ]],
    ]);
    storeMessage(['role' => MessageRole::Tool->value, 'content' => 'Done.', 'tool_call_id' => 'call_done']);

    $messages = replayContext();

    expect($messages)->toHaveCount(3)
        ->and($messages[1]->toolCallId)->toBe('call_done')
        ->and($messages[1]->content)->toBe('Done.')
        ->and($messages[2]->toolCallId)->toBe('call_open')
        ->and($messages[2]->content)->toBe(TranscriptNormaliser::PENDING_RESULT);
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
