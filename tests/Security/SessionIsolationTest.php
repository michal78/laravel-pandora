<?php

declare(strict_types=1);

use Pandora\Pandora\Context\ContextBuilder;
use Pandora\Pandora\Context\ContextRequest;
use Pandora\Pandora\Conversations\Session;
use Pandora\Pandora\Conversations\SessionResolver;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Tests\Fixtures\TestUser;
use Pandora\Pandora\Tests\Support\MakesRuns;

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
