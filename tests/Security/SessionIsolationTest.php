<?php

declare(strict_types=1);

use Pandora\Context\ContextBuilder;
use Pandora\Context\ContextRequest;
use Pandora\Conversations\Session;
use Pandora\Conversations\SessionResolver;
use Pandora\Core\Actor\ActorContext;
use Pandora\Messages\Message;
use Pandora\Tests\Fixtures\TestUser;
use Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/**
 * Acceptance guarantee 16 -- session isolation.
 *
 * A session is a security boundary, not a routing selector. Two users sharing
 * a conversation (or a shared channel inbox) must never see each other's
 * context.
 */
it('derives a stable isolation key for the same identity tuple', function (): void {
    $key = fn () => Session::isolationKeyFor('t1', 'agent-1', ActorContext::system('a'), 'web', null, 'web');

    expect($key())->toBe($key());
});

it('derives a different isolation key for every differing component', function (): void {
    $base = Session::isolationKeyFor('t1', 'agent-1', ActorContext::system('alice'), 'web', null, 'web');

    $variants = [
        Session::isolationKeyFor('t2', 'agent-1', ActorContext::system('alice'), 'web', null, 'web'),
        Session::isolationKeyFor('t1', 'agent-2', ActorContext::system('alice'), 'web', null, 'web'),
        Session::isolationKeyFor('t1', 'agent-1', ActorContext::system('bob'), 'web', null, 'web'),
        Session::isolationKeyFor('t1', 'agent-1', ActorContext::system('alice'), 'slack', null, 'web'),
        Session::isolationKeyFor('t1', 'agent-1', ActorContext::system('alice'), 'web', 'U123', 'web'),
        Session::isolationKeyFor('t1', 'agent-1', ActorContext::system('alice'), 'web', null, 'api'),
    ];

    foreach ($variants as $variant) {
        expect($variant)->not->toBe($base);
    }
});

it('gives two users in one conversation separate sessions', function (): void {
    $agent = $this->makeAgent();
    $conversation = $this->makeConversation($agent);

    $alice = TestUser::create(['name' => 'Alice', 'email' => 'alice@example.test', 'password' => 'x']);
    $bob = TestUser::create(['name' => 'Bob', 'email' => 'bob@example.test', 'password' => 'x']);

    $resolver = app(SessionResolver::class);

    $aliceSession = $resolver->resolve($agent, ActorContext::forUser($alice), $conversation);
    $bobSession = $resolver->resolve($agent, ActorContext::forUser($bob), $conversation);

    expect($aliceSession->getKey())->not->toBe($bobSession->getKey());
});

it('resolves the same session for a repeated identity tuple', function (): void {
    $agent = $this->makeAgent();
    $conversation = $this->makeConversation($agent);
    $alice = TestUser::create(['name' => 'Alice', 'email' => 'a@example.test', 'password' => 'x']);

    $resolver = app(SessionResolver::class);
    $actor = ActorContext::forUser($alice);

    expect($resolver->resolve($agent, $actor, $conversation)->getKey())
        ->toBe($resolver->resolve($agent, $actor, $conversation)->getKey());
});

it('never places another session\'s messages into built context', function (): void {
    $agent = $this->makeAgent();
    $conversation = $this->makeConversation($agent);

    $aliceSession = $this->makeSession($agent);
    $bobSession = $this->makeSession($agent);

    Message::query()->create([
        'conversation_id' => $conversation->getKey(),
        'session_id' => $aliceSession->getKey(),
        'role' => 'user', 'type' => 'text', 'sequence' => 1,
        'content' => 'ALICE PRIVATE MEDICAL DETAIL',
        'streaming_state' => 'complete',
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->getKey(),
        'session_id' => $bobSession->getKey(),
        'role' => 'user', 'type' => 'text', 'sequence' => 2,
        'content' => 'bob asks about pricing',
        'streaming_state' => 'complete',
    ]);

    $run = $this->makeRun([
        'agent_id' => $agent->getKey(),
        'conversation_id' => $conversation->getKey(),
        'session_id' => $bobSession->getKey(),
    ]);

    $context = app(ContextBuilder::class)->build(new ContextRequest(
        run: $run, agent: $agent, session: $bobSession, tokenBudget: 8000,
    ));

    $text = collect($context->messages)->map(fn ($m) => $m->content)->implode("\n");

    expect($text)->toContain('bob asks about pricing')
        ->and($text)->not->toContain('ALICE PRIVATE MEDICAL DETAIL');
});

it('reports that a system session belongs to no actor', function (): void {
    $session = $this->makeSession();

    expect($session->belongsToActor(ActorContext::system('automation')))->toBeFalse()
        ->and($session->belongsToActor(null))->toBeFalse();
});

it('separates two actors that share an id but not a type', function (): void {
    // Phase 9 audit, 2026-08-19: `actor_type` could be dropped from
    // `Session::isolationKeyFor()` and all 1,820 tests stayed green, while
    // dropping `actor_id` beside it failed two. The asymmetry is the whole
    // finding — "derives a different isolation key for every differing
    // component" varies the actor's *id* (system `alice` against system `bob`)
    // and never its type, so half of the actor was asserted and half was not.
    //
    // The collision is reachable rather than theoretical: `ActorContext::system()`
    // takes any label, so an automation labelled with a user's id has the same
    // `id` as that user and a different `type`. Without the type in the key
    // they are one session, and the automation reads the person's history.
    $agent = $this->makeAgent();
    $user = TestUser::create(['name' => 'Alice', 'email' => 'collide@example.test', 'password' => 'x']);

    $sharedId = (string) $user->getKey();

    $asUser = Session::isolationKeyFor('t1', 'agent-1', ActorContext::forUser($user), 'web', null, 'web');
    $asSystem = Session::isolationKeyFor('t1', 'agent-1', ActorContext::system($sharedId), 'web', null, 'web');

    expect(ActorContext::forUser($user)->id)->toBe(ActorContext::system($sharedId)->id)
        ->and($asUser)->not->toBe($asSystem);

    // And through the resolver, which is where it would actually happen.
    $resolver = app(SessionResolver::class);

    expect($resolver->resolve($agent, ActorContext::forUser($user))->getKey())
        ->not->toBe($resolver->resolve($agent, ActorContext::system($sharedId))->getKey());
});

it('does not share a session between two conversations with the same agent and actor', function (): void {
    // `SessionResolver` folds the conversation into the origin component, with
    // a comment saying two conversations with the same agent and actor must not
    // share a context boundary. Removing that fold left the whole suite green:
    // the sentence was documentation, not a control. The unit test above cannot
    // reach it, because it passes `origin` directly and never goes through the
    // composition the resolver performs.
    $agent = $this->makeAgent();
    $user = TestUser::create(['name' => 'Alice', 'email' => 'two-convos@example.test', 'password' => 'x']);
    $actor = ActorContext::forUser($user);

    $first = $this->makeConversation($agent);
    $second = $this->makeConversation($agent);

    $resolver = app(SessionResolver::class);
    $firstSession = $resolver->resolve($agent, $actor, $first);
    $secondSession = $resolver->resolve($agent, $actor, $second);

    expect($firstSession->getKey())->not->toBe($secondSession->getKey());

    // The consequence, asserted where it would be felt: one conversation's
    // history must not appear when building context for the other.
    Message::query()->create([
        'conversation_id' => $first->getKey(),
        'session_id' => $firstSession->getKey(),
        'role' => 'user', 'type' => 'text', 'sequence' => 1,
        'content' => 'FIRST CONVERSATION PRIVATE DETAIL',
        'streaming_state' => 'complete',
    ]);

    $run = $this->makeRun([
        'agent_id' => $agent->getKey(),
        'conversation_id' => $second->getKey(),
        'session_id' => $secondSession->getKey(),
    ]);

    $context = app(ContextBuilder::class)->build(new ContextRequest(
        run: $run, agent: $agent, session: $secondSession, tokenBudget: 8000,
    ));

    $text = collect($context->messages)->map(fn ($m) => $m->content)->implode("\n");

    expect($text)->not->toContain('FIRST CONVERSATION PRIVATE DETAIL');
});
