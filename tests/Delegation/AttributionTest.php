<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pandora\Audit\AuditLog;
use Pandora\Tests\Fixtures\Tools\GatedTool;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\ToolExecution;

/**
 * Phase 6 acceptance criterion 13 — a delegated run is attributable to the
 * PERSON who set the tree in motion, never to the agent that forwarded it.
 *
 * An agent is not an actor. It never was, and delegation is where that could
 * most easily stop being true: it is the one place where something inside
 * Pandora starts a run, and the obvious shortcut is to attribute the child to
 * whichever agent asked for it.
 *
 * Two things break if that shortcut is taken. The trace loses the only identity
 * anybody can be held to. And -- much worse -- `Tool::authorize()` is checked
 * against the actor, so a child acting as its own agent would be a child acting
 * as nobody, sailing past every host authorization check by having no user to
 * fail them.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools([GatedTool::class]);
});

it('carries the initiating actor down to the child run', function (): void {
    $this->makeDelegationPair();
    $user = $this->toolUser();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Answer.')
        ->willRespondWith('Done.');

    $parentRun = $this->runParent();
    $child = $this->childOf($parentRun);

    expect($child->actor_id)->toBe((string) $user->getKey())
        ->and($child->actor_id)->toBe($parentRun->actor_id)
        ->and($child->actor_type)->toBe($parentRun->actor_type);
});

it('never names the parent agent as the actor', function (): void {
    [$parentAgent] = $this->makeDelegationPair();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Answer.')
        ->willRespondWith('Done.');

    $child = $this->childOf($this->runParent());

    expect($child->actor_id)->not->toBe((string) $parentAgent->getKey())
        ->and($child->actor_type)->not->toContain('Agent');
});

it('names the initiating actor on the delegation audit entry', function (): void {
    $this->makeDelegationPair();
    $user = $this->toolUser();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Answer.')
        ->willRespondWith('Done.');

    $this->runParent();

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'delegation.started')->firstOrFail();

    expect($entry->actor_id)->toBe((string) $user->getKey())
        // And it still records which agent did the forwarding -- that is
        // useful, it is just not the actor.
        ->and($entry->metadata['parent_agent'])->not->toBeNull();
});

/**
 * The consequence that matters: a child's tool call is authorized against the
 * same person, so a host gate that would refuse them refuses the delegate too.
 *
 * This is the test that would catch an attribution regression in the only way
 * that really counts -- not by reading a column, but by watching a permission
 * hold one hop away from where it was granted.
 */
it('refuses a child tool call the initiating user is not permitted', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'gated_action'],
        childTools: ['gated_action'],
    );

    // The host's own gate, refusing the person the tree acts for.
    Gate::define('manage-orders', static fn (): bool => false);

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRequestTools([GatedTool::call()])
        ->willRespondWith('I was not allowed to do that.')
        ->willRespondWith('That could not be done.');

    $child = $this->childOf($this->runParent());

    /** @var ToolExecution $attempt */
    $attempt = ToolExecution::query()
        ->where('run_id', $child->getKey())
        ->where('tool_name', 'gated_action')
        ->firstOrFail();

    expect($attempt->status->value)->toBe('denied')
        ->and($attempt->decided_by)->toBe('tool');
});

/**
 * The control: the same delegate, the same tool, a gate that allows.
 *
 * Without this, the test above would pass just as happily if delegated runs
 * could never call any tool at all -- which would be a bug wearing a security
 * property's clothes.
 */
it('permits that same child tool call when the user is allowed', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'gated_action'],
        childTools: ['gated_action'],
    );

    Gate::define('manage-orders', static fn (): bool => true);

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRequestTools([GatedTool::call()])
        ->willRespondWith('Acted on it.')
        ->willRespondWith('Done.');

    $child = $this->childOf($this->runParent());

    /** @var ToolExecution $attempt */
    $attempt = ToolExecution::query()
        ->where('run_id', $child->getKey())
        ->where('tool_name', 'gated_action')
        ->firstOrFail();

    expect($attempt->status->value)->toBe('succeeded');
});
