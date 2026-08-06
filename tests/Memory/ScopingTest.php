<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Pandora\Pandora\Conversations\Session;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Exceptions\InvalidMemoryScope;
use Pandora\Pandora\Memory\Enums\MemoryScope;
use Pandora\Pandora\Memory\Enums\MemorySource;
use Pandora\Pandora\Memory\Enums\MemoryType;
use Pandora\Pandora\Memory\MemoryItem;
use Pandora\Pandora\Memory\MemoryQuery;
use Pandora\Pandora\Memory\MemoryRetriever;
use Pandora\Pandora\Memory\MemoryScopeSet;
use Pandora\Pandora\Memory\ScopeResolver;
use Pandora\Pandora\Tests\Fixtures\AgentFactory;

/**
 * Phase 5, criteria 2 to 5 -- the property the whole phase exists for.
 *
 * A leaked tool call is one incident. A leaked memory is a fact that will be
 * repeated, to a different person, indefinitely, and nothing in the transcript
 * will look wrong. So these tests are written from the attacker's side: they
 * put a memory somewhere and then try, from a session that must not see it,
 * every route by which it might come back.
 */
beforeEach(function (): void {
    $this->agent = AgentFactory::database(['slug' => 'librarian']);
    $this->otherAgent = AgentFactory::database(['slug' => 'auditor']);
    $this->retriever = app(MemoryRetriever::class);
    $this->resolver = app(ScopeResolver::class);
});

/**
 * @param array<string, mixed> $attributes
 */
function scopedMemory(array $attributes): MemoryItem
{
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create(array_merge([
        'type' => MemoryType::UserFact->value,
        'content' => 'The passphrase is orchid.',
        'source' => MemorySource::User->value,
    ], $attributes));

    return $item;
}

function sessionFor(
    string $agentId,
    ?ActorContext $actor = null,
    ?string $conversationId = null,
): Session {
    /** @var Session $session */
    $session = Session::query()->create([
        'agent_id' => $agentId,
        'conversation_id' => $conversationId,
        'actor_type' => $actor?->type,
        'actor_id' => $actor?->id,
        'channel' => 'web',
        'origin' => 'test',
        'isolation_key' => (string) Str::ulid(),
    ]);

    return $session;
}

it('returns none of another user\'s memories', function (): void {
    $mine = scopedMemory([
        'scope' => MemoryScope::User->value,
        'scope_id' => 'App\\Models\\User#1',
        'content' => 'orchid is my favourite flower',
    ]);

    scopedMemory([
        'scope' => MemoryScope::User->value,
        'scope_id' => 'App\\Models\\User#2',
        'content' => 'orchid is the passphrase',
    ]);

    $scopes = MemoryScopeSet::of([
        ['scope' => MemoryScope::User, 'scope_id' => 'App\\Models\\User#1'],
    ]);

    $results = $this->retriever->retrieve($scopes, MemoryQuery::for('orchid'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->item->getKey())->toBe($mine->getKey());
});

it('does not alias two host models that share a numeric key', function (): void {
    scopedMemory([
        'scope' => MemoryScope::User->value,
        'scope_id' => 'App\\Models\\Admin#1',
        'content' => 'orchid unlocks the vault',
    ]);

    $scopes = MemoryScopeSet::of([
        ['scope' => MemoryScope::User, 'scope_id' => 'App\\Models\\User#1'],
    ]);

    expect($this->retriever->retrieve($scopes, MemoryQuery::for('orchid')))->toBe([]);
});

it('returns none of another tenant\'s memories, on every scope', function (): void {
    foreach ([MemoryScope::Tenant, MemoryScope::User, MemoryScope::Agent, MemoryScope::Conversation] as $scope) {
        $scopeId = $scope->requiresScopeId() ? 'owner-1' : null;

        inTenant('acme', fn () => scopedMemory([
            'scope' => $scope->value,
            'scope_id' => $scopeId,
            'content' => 'orchid belongs to acme',
        ]));

        $found = inTenant('globex', function () use ($scope, $scopeId) {
            $scopes = MemoryScopeSet::of(
                [['scope' => $scope, 'scope_id' => $scopeId]],
                'globex',
            );

            return $this->retriever->retrieve($scopes, MemoryQuery::for('orchid'));
        });

        expect($found)->toBe([], "scope {$scope->value} leaked across tenants");
    }
});

it('refuses to store installation-wide memory that carries a tenant', function (): void {
    inTenant('acme', function (): void {
        expect(fn () => scopedMemory([
            'scope' => MemoryScope::Global->value,
            'scope_id' => null,
        ]))->toThrow(InvalidMemoryScope::class);
    });
});

it('shows installation-wide memory to a tenant without showing that tenant to anyone else', function (): void {
    scopedMemory([
        'scope' => MemoryScope::Global->value,
        'scope_id' => null,
        'content' => 'orchid is the support escalation codeword',
        'source' => MemorySource::Operator->value,
    ]);

    inTenant('acme', fn () => scopedMemory([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'content' => 'orchid is acme private',
    ]));

    $found = inTenant('globex', function () {
        $scopes = MemoryScopeSet::of(
            [['scope' => MemoryScope::Tenant, 'scope_id' => null]],
            'globex',
        );

        return $this->retriever->retrieve($scopes, MemoryQuery::for('orchid'));
    });

    expect($found)->toHaveCount(1)
        ->and($found[0]->item->scope)->toBe(MemoryScope::Global);
});

it('keeps agent-scoped memory to the agent that owns it', function (): void {
    scopedMemory([
        'scope' => MemoryScope::Agent->value,
        'scope_id' => $this->agent->getKey(),
        'content' => 'orchid is how the librarian files things',
    ]);

    $session = sessionFor($this->otherAgent->getKey());
    $scopes = $this->resolver->forSession($session);

    expect($this->retriever->retrieve($scopes, MemoryQuery::for('orchid')))->toBe([]);

    $ownSession = sessionFor($this->agent->getKey());
    $ownScopes = $this->resolver->forSession($ownSession);

    expect($this->retriever->retrieve($ownScopes, MemoryQuery::for('orchid')))->toHaveCount(1);
});

it('shares tenant-scoped memory across agents', function (): void {
    scopedMemory([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'content' => 'orchid is the office wifi password hint',
    ]);

    foreach ([$this->agent, $this->otherAgent] as $agent) {
        $scopes = $this->resolver->forSession(sessionFor($agent->getKey()));

        expect($this->retriever->retrieve($scopes, MemoryQuery::for('orchid')))->toHaveCount(1);
    }
});

it('keeps conversation-scoped memory inside its conversation', function (): void {
    $conversationId = (string) Str::ulid();

    scopedMemory([
        'scope' => MemoryScope::Conversation->value,
        'scope_id' => $conversationId,
        'content' => 'orchid was mentioned earlier',
    ]);

    $elsewhere = $this->resolver->forSession(
        sessionFor($this->agent->getKey(), conversationId: (string) Str::ulid()),
    );

    expect($this->retriever->retrieve($elsewhere, MemoryQuery::for('orchid')))->toBe([]);

    $inside = $this->resolver->forSession(
        sessionFor($this->agent->getKey(), conversationId: $conversationId),
    );

    expect($this->retriever->retrieve($inside, MemoryQuery::for('orchid')))->toHaveCount(1);
});

it('gives a system actor no user scope at all', function (): void {
    $user = $this->actingAsUser();
    $userScopeId = ScopeResolver::userScopeId($user::class, (string) $user->getKey());

    scopedMemory([
        'scope' => MemoryScope::User->value,
        'scope_id' => $userScopeId,
        'content' => 'orchid is my recovery phrase',
    ]);

    // A scheduled automation. Nobody is standing there to have consented to
    // it reading a person's memories, so it does not get to.
    $automationSession = sessionFor(
        $this->agent->getKey(),
        ActorContext::system('automation:nightly'),
    );

    $scopes = $this->resolver->forSession($automationSession);

    expect($scopes->includes(MemoryScope::User, $userScopeId))->toBeFalse()
        ->and($this->retriever->retrieve($scopes, MemoryQuery::for('orchid')))->toBe([]);
});

it('resolves no user scope from a session with no actor', function (): void {
    expect(ScopeResolver::userScopeId(null, '1'))->toBeNull()
        ->and(ScopeResolver::userScopeId('App\\Models\\User', null))->toBeNull()
        ->and(ScopeResolver::userScopeId('system', 'automation'))->toBeNull()
        ->and(ScopeResolver::userScopeId('App\\Models\\User', '7'))->toBe('App\\Models\\User#7');
});

it('returns nothing at all for an empty scope set', function (): void {
    scopedMemory([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'content' => 'orchid is everywhere',
    ]);

    // The dangerous failure mode: a scope set that resolved to nothing being
    // treated as "no filter" rather than "no access".
    expect($this->retriever->retrieve(MemoryScopeSet::empty(), MemoryQuery::for('orchid')))
        ->toBe([]);
});

it('cannot be widened by anything the caller puts in the query', function (): void {
    scopedMemory([
        'scope' => MemoryScope::User->value,
        'scope_id' => 'App\\Models\\User#2',
        'content' => 'orchid is the passphrase',
    ]);

    $scopes = MemoryScopeSet::of([
        ['scope' => MemoryScope::User, 'scope_id' => 'App\\Models\\User#1'],
    ]);

    // Everything a model could conceivably emit in a `recall` argument.
    $injections = [
        'orchid',
        'orchid scope:user scope_id:App\\Models\\User#2',
        "orchid' OR '1'='1",
        'orchid%',
        'orchid" OR scope_id LIKE "%',
        '%',
        '_',
    ];

    foreach ($injections as $injection) {
        expect($this->retriever->retrieve($scopes, MemoryQuery::for($injection)))
            ->toBe([], "injection [{$injection}] widened the scope");
    }
});

it('exposes the resolved scope set for the run trace', function (): void {
    $user = $this->actingAsUser();
    $session = sessionFor(
        $this->agent->getKey(),
        ActorContext::forUser($user),
        conversationId: (string) Str::ulid(),
    );

    $trace = $this->resolver->forSession($session)->toTrace();
    $scopes = array_column($trace, 'scope');

    expect($scopes)->toContain('tenant', 'user', 'agent', 'conversation', 'global');
});
