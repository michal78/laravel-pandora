<?php

declare(strict_types=1);

use Pandora\Agents\Agent;
use Pandora\Delegation\AbilityIntersection;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\RunStep;
use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Tools\ToolExecution;

/**
 * Phase 6 acceptance criteria 2, 3 and 4 — T8, the property the whole phase
 * turns on.
 *
 * A child run's abilities are the INTERSECTION of the parent's and the child
 * agent's. Not the union, not the child agent's list on its own, and not
 * "whatever the child agent was configured with, since an operator wrote that
 * down deliberately".
 *
 * The failure this prevents is one hop long. A support agent denied refunds
 * does not need refunds if it can delegate to an agent that has them. Were that
 * possible, every allowlist in the product would be a suggestion.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools([LookupOrderTool::class, RefundOrderTool::class]);
});

/**
 * The headline: an ability the parent lacks is absent from the child, even
 * though the child agent's own allowlist grants it.
 */
it('withholds an ability the parent does not have, whatever the child agent allows', function (): void {
    // The parent may delegate and look things up. It may NOT refund.
    // The child agent may refund. It must not get to.
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['lookup_order', 'refund_order'],
    );

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Specialist answer.')
        ->willRespondWith('Done.');

    $child = $this->childOf($this->runParent());

    expect($child->effective_tools)->toContain('lookup_order')
        ->and($child->effective_tools)->not->toContain('refund_order');
});

/**
 * Criterion 3. The list above is not decorative -- the gatekeeper enforces it.
 *
 * The child agent's own allowlist grants `refund_order`, so layer 2's ordinary
 * check passes. Only the frozen intersection stops the call.
 */
it('refuses a tool call the child agent allows but the parent never had', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['refund_order'],
    );

    $this->fakeProvider()
        // Parent delegates.
        ->willRequestTools([$this->delegateCall()])
        // Child immediately reaches for the tool its own allowlist grants.
        ->willRequestTools([$this->refundCall()])
        ->willRespondWith('I was not able to issue the refund.')
        ->willRespondWith('Sorry, that could not be done.');

    $parentRun = $this->runParent();
    $child = $this->childOf($parentRun);

    /** @var ToolExecution $attempt */
    $attempt = ToolExecution::query()
        ->where('run_id', $child->getKey())
        ->where('tool_name', 'refund_order')
        ->firstOrFail();

    expect($attempt->status->isTerminal())->toBeTrue()
        ->and($attempt->status->value)->toBe('denied')
        ->and($attempt->decided_by)->toBe(AuthorizationLayer::Agent->value);
});

/**
 * The same tool, called by the same agent, in a run that is NOT delegated.
 *
 * The control for the test above. Without it, that test would still pass if the
 * child agent's allowlist had simply been misread -- this proves the refusal
 * comes from the delegation and nothing else.
 */
it('allows that same tool when the agent runs directly rather than as a delegate', function (): void {
    $this->agentAllows(['refund_order']);

    $this->fakeProvider()
        ->willRequestTools([$this->refundCall()])
        ->willRespondWith('Refunded.');

    $run = $this->runToolAgent('Refund order ORD-1234.');

    /** @var ToolExecution $attempt */
    $attempt = ToolExecution::query()
        ->where('run_id', $run->getKey())
        ->where('tool_name', 'refund_order')
        ->firstOrFail();

    expect($attempt->status->value)->not->toBe('denied')
        ->and($run->effective_tools)->toBeNull();
});

it('grants a tool both sides hold', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order', 'refund_order'],
        childTools: ['lookup_order'],
    );

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRequestTools([$this->lookupCall()])
        ->willRespondWith('It shipped.')
        ->willRespondWith('It shipped.');

    $child = $this->childOf($this->runParent());

    /** @var ToolExecution $attempt */
    $attempt = ToolExecution::query()
        ->where('run_id', $child->getKey())
        ->where('tool_name', 'lookup_order')
        ->firstOrFail();

    expect($attempt->status->value)->toBe('succeeded');
});

/**
 * Criterion 4, first half: the intersection is a stored fact.
 *
 * Stored rather than derived, so that an operator widening the child agent's
 * allowlist tomorrow cannot change what a run that finished today was allowed
 * to do -- either in effect or in the record of it.
 */
it('persists the intersection on the child run', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['lookup_order', 'refund_order'],
    );

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Answer.')
        ->willRespondWith('Done.');

    $child = $this->childOf($this->runParent());
    $stored = $child->effective_tools;

    // Widen the child agent AFTER the fact. The frozen list must not move.
    $agent = Agent::query()->findOrFail($child->agent_id);
    $agent->forceFill(['tool_policy' => ['allow' => ['refund_order', 'lookup_order']]])->save();

    expect($child->fresh()->effective_tools)->toBe($stored)
        ->and($stored)->not->toContain('refund_order');
});

/** Criterion 4, second half: the trace reproduces it. */
it('reproduces the intersection in the parent trace', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['lookup_order', 'refund_order'],
    );

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Answer.')
        ->willRespondWith('Done.');

    $parentRun = $this->runParent();

    /** @var RunStep $step */
    $step = RunStep::query()
        ->where('run_id', $parentRun->getKey())
        ->where('type', RunStepType::Delegation->value)
        ->orderBy('sequence')
        ->firstOrFail();

    expect($step->payload['effective_tools'])->toContain('lookup_order')
        // The trace says what was withheld as well as what was granted. After
        // an incident the question is what the child could NOT do, and two
        // allowlists and a config file some weeks later is how that gets
        // answered wrongly and confidently.
        ->and($step->payload['withheld_tools'])->toContain('refund_order');
});

/**
 * Abilities shrink as a tree deepens. They never recover.
 *
 * A grandchild intersects against its parent's already-narrowed set rather than
 * against the root's. Two hops cannot restore what one hop gave up -- which is
 * what makes the property hold at any depth rather than only at the first.
 */
it('narrows further at each hop and never widens back', function (): void {
    $intersection = app(AbilityIntersection::class);

    $root = $this->makeAgent(['tool_policy' => ['allow' => ['lookup_order', 'refund_order']]]);
    $middle = $this->makeAgent(['tool_policy' => ['allow' => ['lookup_order', 'refund_order']]]);

    $rootRun = $this->makeRun(['agent_id' => $root->getKey()]);

    $childTools = $intersection->between($rootRun, $root, $middle);
    expect($childTools)->toContain('lookup_order')->toContain('refund_order');

    // The middle run was narrowed to lookup only -- as if the root had been.
    $middleRun = $this->makeRun([
        'agent_id' => $middle->getKey(),
        'parent_run_id' => $rootRun->getKey(),
        'delegation_depth' => 1,
        'effective_tools' => ['lookup_order'],
    ]);

    // The grandchild agent allows both. It must still only get lookup.
    $grandchild = $this->makeAgent(['tool_policy' => ['allow' => ['lookup_order', 'refund_order']]]);

    expect($intersection->between($middleRun, $middle, $grandchild))
        ->toBe(['lookup_order']);
});

/**
 * An empty intersection is not the same as no intersection.
 *
 * `null` means "not a delegated run, the agent's allowlist applies". `[]` means
 * "a delegation that overlapped in nothing, and this child may call no tools at
 * all". Conflating them would turn the most restrictive delegation possible
 * into an unrestricted one, which is the wrong direction to be wrong in.
 */
it('distinguishes an empty intersection from no intersection at all', function (): void {
    $this->makeDelegationPair(
        parentTools: ['delegate_to_agent'],
        childTools: ['refund_order'],
    );

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRequestTools([$this->refundCall()])
        ->willRespondWith('I could not do that.')
        ->willRespondWith('Done.');

    $child = $this->childOf($this->runParent());

    expect($child->effective_tools)->toBe([])
        ->and($child->effective_tools)->not->toBeNull();

    /** @var ToolExecution $attempt */
    $attempt = ToolExecution::query()
        ->where('run_id', $child->getKey())
        ->where('tool_name', 'refund_order')
        ->firstOrFail();

    expect($attempt->status->value)->toBe('denied');
});
