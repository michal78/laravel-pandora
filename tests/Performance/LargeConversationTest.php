<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Pandora\Context\ContextBuilder;
use Pandora\Context\ContextRequest;
use Pandora\Conversations\Conversation;
use Pandora\Conversations\Session;
use Pandora\Messages\Message;
use Pandora\Runs\Enums\RunState;
use Pandora\Tests\Support\MakesRuns;
use Pandora\Tests\Support\MakesTools;
use Pandora\UI\Livewire\Chat;
use Symfony\Component\Uid\Ulid;

/**
 * Phase 9, criterion 26 -- a conversation of 10,000 messages builds context
 * within budget, and the chat page renders without loading all of them.
 *
 * A conversation has no length limit, and both of these read it on every
 * turn: the context builder once per model call, the chat page on every poll
 * and broadcast. The context builder was already windowed; the chat page was
 * not, and loaded every message of the conversation on every re-render.
 *
 * "Without loading all of them" is asserted as hydrated models and as query
 * count, compared against a conversation a hundred times shorter. A page that
 * is merely fast enough on today's machine passes a timing assertion and
 * fails this one.
 */
uses(MakesRuns::class, MakesTools::class);

const LARGE_CONVERSATION = 10_000;

/**
 * @return array{0: Conversation, 1: Session}
 */
function seedConversation(int $messages): array
{
    $user = test()->toolUser();
    $agent = test()->agent();

    $conversation = test()->makeConversation($agent, [
        'created_by_type' => $user::class,
        'created_by_id' => (string) $user->getKey(),
    ]);
    $session = test()->makeSession($agent, ['conversation_id' => $conversation->getKey()]);

    $table = (new Message)->getTable();
    $now = now();

    // Inserted raw and in chunks: ten thousand Eloquent creates would make
    // this test about the seeder.
    foreach (array_chunk(range(1, $messages), 500) as $chunk) {
        DB::table($table)->insert(array_map(static fn (int $sequence): array => [
            'id' => (string) new Ulid,
            'conversation_id' => $conversation->getKey(),
            'session_id' => $session->getKey(),
            'role' => $sequence % 2 === 1 ? 'user' : 'assistant',
            'type' => 'text',
            'sequence' => $sequence,
            'content' => "Message number {$sequence}.",
            'content_format' => 'markdown',
            'streaming_state' => 'complete',
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }

    return [$conversation, $session];
}

/**
 * Runs the callback and reports how many queries it issued and how many
 * messages it hydrated.
 *
 * @return array{queries: int, messages: int}
 */
function measure(Closure $callback): array
{
    $hydrated = 0;
    $counting = true;

    // Not removed afterwards: flushing a model's listeners also strips the
    // package's own hooks. The listener dies with the test's application.
    Message::retrieved(static function () use (&$hydrated, &$counting): void {
        if ($counting) {
            $hydrated++;
        }
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $counting = false;

    return ['queries' => $queries, 'messages' => $hydrated];
}

beforeEach(function (): void {
    $this->actingAsUser($this->toolUser());
});

it('builds context for a 10,000-message conversation within budget', function (): void {
    [$conversation, $session] = seedConversation(LARGE_CONVERSATION);

    $run = $this->makeRun([
        'agent_id' => $this->agent()->getKey(),
        'session_id' => $session->getKey(),
        'conversation_id' => $conversation->getKey(),
        'state' => RunState::Running->value,
    ]);

    $budget = (int) $this->agent()->context_budget_tokens;
    $built = null;

    $cost = measure(function () use (&$built, $run, $session, $budget): void {
        $built = app(ContextBuilder::class)->build(
            new ContextRequest($run, $this->agent(), $session, $budget),
        );
    });

    $limit = (int) config('pandora.context.recent_messages.limit');

    expect($built->estimatedTokens)->toBeLessThanOrEqual($budget)
        ->and($cost['messages'])->toBeLessThanOrEqual($limit)
        // The window is the most recent messages, not the first ones.
        ->and(collect($built->messages)->pluck('content')->implode("\n"))
        ->toContain('Message number '.LARGE_CONVERSATION.'.')
        ->not->toContain('Message number 1.');
});

it('renders the chat page for a 10,000-message conversation without loading them all', function (): void {
    [$conversation] = seedConversation(LARGE_CONVERSATION);

    $component = null;
    $cost = measure(function () use (&$component, $conversation): void {
        $component = Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()]);
    });

    $component->assertOk()
        ->assertSee('Message number '.LARGE_CONVERSATION.'.')
        ->assertDontSee('Message number 1.')
        ->assertSee('Show earlier messages');

    expect($cost['messages'])->toBeLessThanOrEqual(Chat::MESSAGE_PAGE);
});

it('issues the same number of queries for a conversation 100 times longer', function (): void {
    [$short] = seedConversation(LARGE_CONVERSATION / 100);
    [$long] = seedConversation(LARGE_CONVERSATION);

    $render = static fn (Conversation $c): Closure => static function () use ($c): void {
        Livewire::test(Chat::class, ['conversation' => (string) $c->getKey()]);
    };

    expect(measure($render($long))['queries'])->toBe(measure($render($short))['queries']);
});

it('shows earlier messages a page at a time', function (): void {
    [$conversation] = seedConversation(LARGE_CONVERSATION);

    $last = LARGE_CONVERSATION;
    $firstOfSecondPage = $last - 2 * Chat::MESSAGE_PAGE + 1;

    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->assertDontSee("Message number {$firstOfSecondPage}.")
        ->call('showEarlierMessages')
        ->assertSee("Message number {$firstOfSecondPage}.")
        ->assertDontSee('Message number '.($firstOfSecondPage - 1).'.');
});

it('offers no earlier messages when the whole conversation fits', function (): void {
    [$conversation] = seedConversation(Chat::MESSAGE_PAGE);

    Livewire::test(Chat::class, ['conversation' => (string) $conversation->getKey()])
        ->assertSee('Message number 1.')
        ->assertDontSee('Show earlier messages');
});
