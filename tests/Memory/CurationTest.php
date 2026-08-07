<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Pandora\Audit\AuditLog;
use Pandora\Conversations\Session;
use Pandora\Exceptions\InvalidMemoryScope;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySensitivity;
use Pandora\Memory\Enums\MemoryStatus;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryCurator;
use Pandora\Memory\MemoryItem;
use Pandora\Memory\MemoryQuery;
use Pandora\Memory\MemoryRetriever;
use Pandora\Memory\MemoryWriter;
use Pandora\Memory\ScopeResolver;
use Pandora\Tests\Fixtures\AgentFactory;

/**
 * Phase 5, criteria 11 and 12 -- a human between the agent and the claim.
 *
 * An agent that can write a fact about a person and have it believed on the
 * next turn has no supervision at all. So a sensitive write lands as
 * `Suggested`: it exists, a person can see it, and no agent can read it back.
 * An agent that could retrieve its own unapproved suggestion has approved it
 * itself.
 */
beforeEach(function (): void {
    Gate::define('pandora.memory.manage', static fn (): bool => true);
    $this->user = $this->actingAsUser();

    $this->agent = AgentFactory::database(['slug' => 'curator']);
    $this->writer = app(MemoryWriter::class);
    $this->curator = app(MemoryCurator::class);
    $this->retriever = app(MemoryRetriever::class);

    /** @var Session $session */
    $session = Session::query()->create([
        'agent_id' => $this->agent->getKey(),
        'conversation_id' => (string) Str::ulid(),
        'actor_type' => $this->user::class,
        'actor_id' => (string) $this->user->getKey(),
        'channel' => 'web',
        'origin' => 'test',
        'isolation_key' => (string) Str::ulid(),
    ]);
    $this->session = $session;

    $this->scopes = app(ScopeResolver::class)->forSession($session);
});

it('holds a sensitive fact for review instead of storing it active', function (): void {
    $item = $this->writer->remember(
        session: $this->session,
        content: 'Prefers to be contacted after their therapy appointment on Tuesdays.',
        scope: MemoryScope::User,
        type: MemoryType::UserFact,
    );

    expect($item->sensitivity)->toBe(MemorySensitivity::Sensitive)
        ->and($item->status)->toBe(MemoryStatus::Suggested);

    // Invisible to the agent that wrote it.
    expect($this->retriever->retrieve($this->scopes, MemoryQuery::for('therapy appointment tuesdays')))
        ->toBe([]);

    expect(AuditLog::query()->where('action', 'memory.suggested')->count())->toBe(1);
});

it('treats any claim about a person as needing review, whatever words it uses', function (): void {
    // The case no keyword list covers, and the one that matters most.
    $item = $this->writer->remember(
        session: $this->session,
        content: 'Always books the aisle seat.',
        scope: MemoryScope::User,
        type: MemoryType::UserFact,
    );

    expect($item->status)->toBe(MemoryStatus::Suggested);
});

it('stores an ordinary agent-scoped note active, with no ceremony', function (): void {
    $item = $this->writer->remember(
        session: $this->session,
        content: 'Deploy notes are filed under the release date.',
        scope: MemoryScope::Agent,
        type: MemoryType::AgentCurated,
    );

    expect($item->sensitivity)->toBe(MemorySensitivity::Normal)
        ->and($item->status)->toBe(MemoryStatus::Active)
        ->and($this->retriever->retrieve($this->scopes, MemoryQuery::for('deploy notes filed')))
        ->toHaveCount(1);
});

it('refuses to store a credential in any status at all', function (): void {
    $item = $this->writer->remember(
        session: $this->session,
        content: 'The admin password is written on the whiteboard.',
        scope: MemoryScope::Agent,
        type: MemoryType::AgentCurated,
    );

    // Not stored, not suggested, not queued for anyone to approve. There is no
    // version of keeping this that is correct.
    expect($item)->toBeNull()
        ->and(MemoryItem::withTrashed()->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'memory.refused')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->severity)->toBe('warning');
});

it('redacts a credential before the row exists, not when it is rendered', function (): void {
    $item = $this->writer->remember(
        session: $this->session,
        content: 'The deploy webhook uses sk-abcdefghijklmnopqrstuvwxyz for auth.',
        scope: MemoryScope::Agent,
        type: MemoryType::AgentCurated,
    );

    // The row itself, straight from the database, with no accessor in the way.
    $raw = DB::table('pandora_memory_items')->where('id', $item->getKey())->value('content');

    expect($raw)->not->toContain('sk-abcdefghijklmnopqrstuvwxyz')
        ->and($raw)->toContain('[redacted]');
});

it('makes a memory retrievable once a person approves it', function (): void {
    $item = $this->writer->remember(
        session: $this->session,
        content: 'Always books the aisle seat.',
        scope: MemoryScope::User,
        type: MemoryType::UserFact,
    );

    expect($this->retriever->retrieve($this->scopes, MemoryQuery::for('aisle seat')))->toBe([]);

    $this->curator->approve($item, 'confirmed with them');

    expect($item->refresh()->status)->toBe(MemoryStatus::Active)
        ->and($this->retriever->retrieve($this->scopes, MemoryQuery::for('aisle seat')))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'memory.approved')->count())->toBe(1);
});

it('never makes a rejected memory retrievable', function (): void {
    $item = $this->writer->remember(
        session: $this->session,
        content: 'Always books the aisle seat.',
        scope: MemoryScope::User,
        type: MemoryType::UserFact,
    );

    $this->curator->reject($item, 'they said this is wrong');

    expect($item->refresh()->status)->toBe(MemoryStatus::Rejected)
        ->and($this->retriever->retrieve($this->scopes, MemoryQuery::for('aisle seat')))->toBe([]);

    // Kept rather than deleted, so the same suggestion is not re-proposed and
    // re-reviewed forever.
    expect(MemoryItem::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'memory.rejected')->count())->toBe(1);
});

it('does not embed a memory until it is approved', function (): void {
    $item = $this->writer->remember(
        session: $this->session,
        content: 'Always books the aisle seat.',
        scope: MemoryScope::User,
        type: MemoryType::UserFact,
    );

    // Embedding on write would put an unapproved claim into the vector index,
    // where a store dump would still contain it.
    expect($item->embedding_id)->toBeNull();

    $this->curator->approve($item);

    expect($item->refresh()->embedding_id)->not->toBeNull();
});

it('requires the manage ability to approve, reject or forget', function (): void {
    Gate::define('pandora.memory.manage', static fn (): bool => false);

    $item = $this->writer->remember(
        session: $this->session,
        content: 'Always books the aisle seat.',
        scope: MemoryScope::User,
        type: MemoryType::UserFact,
    );

    expect(fn () => $this->curator->approve($item))->toThrow(AuthorizationException::class);
    expect(fn () => $this->curator->reject($item))->toThrow(AuthorizationException::class);
    expect(fn () => $this->curator->forget($item))->toThrow(AuthorizationException::class);
});

it('refuses a write to a scope the session cannot identify', function (): void {
    /** @var Session $automation */
    $automation = Session::query()->create([
        'agent_id' => $this->agent->getKey(),
        'actor_type' => 'system',
        'actor_id' => 'automation:nightly',
        'channel' => 'schedule',
        'origin' => 'test',
        'isolation_key' => (string) Str::ulid(),
    ]);

    // Refused rather than silently downgraded to a wider scope, which would be
    // the leak wearing a helpful face.
    expect(fn () => $this->writer->remember(
        session: $automation,
        content: 'Something about whoever is not here.',
        scope: MemoryScope::User,
        type: MemoryType::UserFact,
    ))->toThrow(InvalidMemoryScope::class);
});

it('refuses an agent write to installation-wide memory', function (): void {
    // One agent teaching every other agent something false, once.
    expect(fn () => $this->writer->remember(
        session: $this->session,
        content: 'Everyone should know this.',
        scope: MemoryScope::Global,
        type: MemoryType::AgentCurated,
    ))->toThrow(InvalidMemoryScope::class);
});

it('files a write under the session\'s own identity, never a supplied one', function (): void {
    $item = $this->writer->remember(
        session: $this->session,
        content: 'Deploy notes are filed under the release date.',
        scope: MemoryScope::User,
        type: MemoryType::AgentCurated,
    );

    $expected = ScopeResolver::userScopeId($this->user::class, (string) $this->user->getKey());

    expect($item->scope_id)->toBe($expected);
});

it('records provenance so a person can tell a claim from a statement', function (): void {
    $item = $this->writer->remember(
        session: $this->session,
        content: 'Deploy notes are filed under the release date.',
        scope: MemoryScope::Agent,
        runId: null,
        provenance: ['stated_in' => 'conversation'],
    );

    expect($item->provenance)->toBe(['stated_in' => 'conversation'])
        ->and($item->agent_id)->toBe($this->agent->getKey());
});

it('gives working memory a default expiry and durable memory none', function (): void {
    $working = $this->writer->remember(
        session: $this->session,
        content: 'Checking the staging logs right now.',
        scope: MemoryScope::Conversation,
        type: MemoryType::Working,
    );

    $durable = $this->writer->remember(
        session: $this->session,
        content: 'Deploy notes are filed under the release date.',
        scope: MemoryScope::Agent,
        type: MemoryType::AgentCurated,
    );

    // Working memory that never expires is a leak of a different kind: the
    // retrieval set grows without bound and the useful facts sink.
    expect($working->expires_at)->not->toBeNull()
        ->and($durable->expires_at)->toBeNull();
});
