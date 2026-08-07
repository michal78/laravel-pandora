<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Pandora\Context\Summariser;
use Pandora\Conversations\Conversation;
use Pandora\Conversations\Session;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryItem;
use Pandora\Messages\Enums\MessageRole;
use Pandora\Messages\Enums\StreamingState;
use Pandora\Messages\Message;
use Pandora\Tests\Fixtures\AgentFactory;

/**
 * Phase 5, criterion 24 -- a summary is a stored artefact.
 *
 * Regenerating per request costs a model call every turn and makes the same
 * conversation produce different context twice, so an agent that answered
 * correctly at 10:00 and wrongly at 10:01 gives you nothing to compare.
 */
beforeEach(function (): void {
    $this->agent = AgentFactory::database(['slug' => 'summariser']);

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->create([
        'agent_id' => $this->agent->getKey(),
        'title' => 'A long chat',
    ]);
    $this->conversation = $conversation;

    /** @var Session $session */
    $session = Session::query()->create([
        'agent_id' => $this->agent->getKey(),
        'conversation_id' => $conversation->getKey(),
        'channel' => 'web',
        'origin' => 'test',
        'isolation_key' => (string) Str::ulid(),
    ]);
    $this->session = $session;

    $this->sequence = 0;
});

function say(Session $session, string $content, ?Session $attributeTo = null): Message
{
    $owner = $attributeTo ?? $session;

    /** @var Message $message */
    $message = Message::query()->create([
        'conversation_id' => $session->conversation_id,
        'session_id' => $owner->getKey(),
        'agent_id' => $session->agent_id,
        'role' => MessageRole::User->value,
        'content' => $content,
        'sequence' => ++test()->sequence,
        'streaming_state' => StreamingState::Complete->value,
    ]);

    return $message;
}

it('is not due before the threshold and is due after it', function (): void {
    $summariser = new Summariser(threshold: 5);

    foreach (range(1, 4) as $n) {
        say($this->session, "message {$n}");
    }

    expect($summariser->isDue($this->session))->toBeFalse();

    say($this->session, 'message 5');

    expect($summariser->isDue($this->session))->toBeTrue();
});

it('stores the summary as a conversation-scoped memory item', function (): void {
    $summariser = new Summariser(threshold: 2);
    say($this->session, 'we discussed the deploy window');
    say($this->session, 'it is thursday');

    $item = $summariser->store($this->session, 'The deploy window is Thursday.');

    expect($item->scope)->toBe(MemoryScope::Conversation)
        ->and($item->scope_id)->toBe($this->conversation->getKey())
        ->and($item->type)->toBe(MemoryType::Summary)
        ->and($item->source)->toBe(MemorySource::Summariser)
        ->and($item->content)->toBe('The deploy window is Thursday.');
});

it('is no longer due immediately after summarising', function (): void {
    $summariser = new Summariser(threshold: 3);

    foreach (range(1, 3) as $n) {
        say($this->session, "message {$n}");
    }

    expect($summariser->isDue($this->session))->toBeTrue();

    $summariser->store($this->session, 'a summary');

    // The property that stops a summary being regenerated on every request
    // once the threshold is first crossed.
    expect($summariser->isDue($this->session))->toBeFalse();
});

it('becomes due again once the threshold is crossed a second time', function (): void {
    $summariser = new Summariser(threshold: 3);

    foreach (range(1, 3) as $n) {
        say($this->session, "message {$n}");
    }

    $summariser->store($this->session, 'first summary');

    foreach (range(4, 5) as $n) {
        say($this->session, "message {$n}");
    }

    expect($summariser->isDue($this->session))->toBeFalse();

    say($this->session, 'message 6');

    expect($summariser->isDue($this->session))->toBeTrue();
});

it('supersedes the previous summary rather than accumulating them', function (): void {
    $summariser = new Summariser(threshold: 2);
    say($this->session, 'one');
    say($this->session, 'two');

    $first = $summariser->store($this->session, 'first summary');
    $second = $summariser->store($this->session, 'second summary');

    // Two live summaries of one conversation both match a retrieval, and the
    // model is handed two overlapping accounts of the same events.
    $live = MemoryItem::query()
        ->where('type', MemoryType::Summary->value)
        ->where('scope_id', $this->conversation->getKey())
        ->get();

    expect($live)->toHaveCount(1)
        ->and($live->first()->getKey())->toBe($second->getKey())
        ->and($second->provenance['supersedes'])->toBe($first->getKey())
        ->and(MemoryItem::withTrashed()->count())->toBe(2);
});

it('summarises only this session\'s messages, never the whole conversation', function (): void {
    // The laundering route this closes: unreadable messages in, readable
    // summary out. A summary built from the whole conversation would carry
    // another participant's words past the session boundary.
    /** @var Session $other */
    $other = Session::query()->create([
        'agent_id' => $this->agent->getKey(),
        'conversation_id' => $this->conversation->getKey(),
        'channel' => 'web',
        'origin' => 'test',
        'isolation_key' => (string) Str::ulid(),
    ]);

    say($this->session, 'mine');
    say($this->session, 'also mine', attributeTo: $other);

    $transcript = (new Summariser)->transcript($this->session);

    expect($transcript)->toHaveCount(1)
        ->and($transcript[0]->content)->toBe('mine');
});

it('returns no summary for a session with no conversation', function (): void {
    /** @var Session $loose */
    $loose = Session::query()->create([
        'agent_id' => $this->agent->getKey(),
        'channel' => 'web',
        'origin' => 'test',
        'isolation_key' => (string) Str::ulid(),
    ]);

    $summariser = new Summariser(threshold: 1);

    expect($summariser->isDue($loose))->toBeFalse()
        ->and($summariser->current($loose))->toBeNull()
        ->and($summariser->transcript($loose))->toBe([]);
});

it('reads back the current summary', function (): void {
    $summariser = new Summariser(threshold: 1);
    say($this->session, 'one');

    expect($summariser->current($this->session))->toBeNull();

    $stored = $summariser->store($this->session, 'the summary');

    expect($summariser->current($this->session)?->getKey())->toBe($stored->getKey());
});
