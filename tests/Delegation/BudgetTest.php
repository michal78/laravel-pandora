<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Pandora\Exceptions\BudgetExceeded;
use Pandora\Runs\Enums\RunState;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\ToolExecution;
use Pandora\Usage\BudgetGuard;
use Pandora\Usage\UsageRecord;

/**
 * Phase 6 acceptance criteria 8 and 9 — one budget, one tree.
 *
 * A budget that reset per child would not be a budget. It would be a
 * multiplier, with `max_depth` as its exponent, and nothing on the agent row
 * would say so. T7 is the threat that pays for getting this wrong.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools();
});

/**
 * Spend recorded against a run, as the recorder would write it.
 */
function treeSpend(string $runId, int $tokens, int $costMicro = 0): UsageRecord
{
    /** @var UsageRecord $record */
    $record = UsageRecord::query()->create([
        'run_id' => $runId,
        'provider_key' => 'fake',
        'model_key' => 'fake-model',
        'input_tokens' => $tokens,
        'output_tokens' => 0,
        'total_tokens' => $tokens,
        'cost_micro' => $costMicro,
        'requests' => 1,
        'occurred_at' => Carbon::now(),
    ]);

    return $record;
}

/**
 * Criterion 8, first half: a child's spend is debited to the parent's tree.
 *
 * The child has spent nothing of its own. It is stopped by what its PARENT
 * already spent, which is the whole point -- the tree is the unit.
 */
it('counts the parent spend against the child', function (): void {
    $parentAgent = $this->makeAgent(['token_budget' => 100]);
    $childAgent = $this->makeAgent();

    $parentRun = $this->makeRun(['agent_id' => $parentAgent->getKey()]);
    $childRun = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parentRun->getKey(),
        'delegation_depth' => 1,
    ]);

    treeSpend((string) $parentRun->getKey(), 100);

    expect(fn () => app(BudgetGuard::class)->assert($childRun, $childAgent))
        ->toThrow(BudgetExceeded::class);
});

/** Criterion 8, second half: and the parent is stopped by what the child spent. */
it('counts the child spend against the parent', function (): void {
    $parentAgent = $this->makeAgent(['token_budget' => 100]);
    $childAgent = $this->makeAgent();

    $parentRun = $this->makeRun(['agent_id' => $parentAgent->getKey()]);
    $childRun = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parentRun->getKey(),
        'delegation_depth' => 1,
    ]);

    treeSpend((string) $childRun->getKey(), 100);

    expect(fn () => app(BudgetGuard::class)->assert($parentRun, $parentAgent))
        ->toThrow(BudgetExceeded::class);
});

/**
 * A child cannot raise the ceiling by being generously configured.
 *
 * The limit comes from the agent at the ROOT of the tree. Reading it from the
 * child would let an operator who widened one specialist agent quietly widen
 * every tree that delegates to it.
 */
it('takes the limit from the root agent, not the child agent', function (): void {
    $parentAgent = $this->makeAgent(['token_budget' => 100]);
    // Ten times the budget. It must not apply.
    $childAgent = $this->makeAgent(['token_budget' => 1000]);

    $parentRun = $this->makeRun(['agent_id' => $parentAgent->getKey()]);
    $childRun = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parentRun->getKey(),
        'delegation_depth' => 1,
    ]);

    treeSpend((string) $parentRun->getKey(), 150);

    expect(fn () => app(BudgetGuard::class)->assert($childRun, $childAgent))
        ->toThrow(BudgetExceeded::class);
});

it('leaves the child room when the tree has budget left', function (): void {
    $parentAgent = $this->makeAgent(['token_budget' => 100]);
    $childAgent = $this->makeAgent();

    $parentRun = $this->makeRun(['agent_id' => $parentAgent->getKey()]);
    $childRun = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parentRun->getKey(),
        'delegation_depth' => 1,
    ]);

    treeSpend((string) $parentRun->getKey(), 99);

    app(BudgetGuard::class)->assert($childRun, $childAgent);
})->throwsNoExceptions();

it('sums a monetary budget across the tree too', function (): void {
    $parentAgent = $this->makeAgent(['cost_budget_minor' => 100]);
    $childAgent = $this->makeAgent();

    $parentRun = $this->makeRun(['agent_id' => $parentAgent->getKey()]);
    $childRun = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parentRun->getKey(),
        'delegation_depth' => 1,
    ]);

    // 60 minor units each: neither alone breaches, together they do.
    treeSpend((string) $parentRun->getKey(), 0, 600_000);
    treeSpend((string) $childRun->getKey(), 0, 600_000);

    expect(fn () => app(BudgetGuard::class)->assert($childRun, $childAgent))
        ->toThrow(BudgetExceeded::class);
});

/**
 * A sibling's spend counts too. The unit is the tree, not the chain.
 */
it('sums across siblings, not just ancestors', function (): void {
    $parentAgent = $this->makeAgent(['token_budget' => 100]);
    $childAgent = $this->makeAgent();

    $parentRun = $this->makeRun(['agent_id' => $parentAgent->getKey()]);
    $first = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parentRun->getKey(),
        'delegation_depth' => 1,
    ]);
    $second = $this->makeRun([
        'agent_id' => $childAgent->getKey(),
        'parent_run_id' => $parentRun->getKey(),
        'delegation_depth' => 1,
    ]);

    treeSpend((string) $first->getKey(), 100);

    expect(fn () => app(BudgetGuard::class)->assert($second, $childAgent))
        ->toThrow(BudgetExceeded::class);
});

/**
 * An ordinary run is unaffected, and asks no extra queries it does not need.
 */
it('leaves an undelegated run counting only itself', function (): void {
    $agent = $this->makeAgent(['token_budget' => 100]);
    $run = $this->makeRun(['agent_id' => $agent->getKey()]);
    $unrelated = $this->makeRun(['agent_id' => $agent->getKey()]);

    treeSpend((string) $unrelated->getKey(), 500);

    app(BudgetGuard::class)->assert($run, $agent);

    expect($run->treeRunIds())->toBe([(string) $run->getKey()]);
});

/**
 * Criterion 9 — end to end. The child exhausts the shared budget, ends, and the
 * PARENT IS TOLD rather than the tree carrying on or the parent waiting out its
 * own deadline.
 *
 * Note the terminal state. A budget breach ends a run as `timed_out` carrying
 * `BudgetExceeded`, which is Pandora's existing convention from Phase 3 rather
 * than something this phase invented. What matters for this criterion is that
 * the parent is told specifically that the budget is gone -- "the delegate
 * failed" would leave a model free to try delegating again.
 */
it('ends the child and tells the parent when the shared budget is exhausted', function (): void {
    [$parent] = $this->makeDelegationPair();

    // One token for the whole tree, and the parent's first turn spends it.
    $parent->forceFill(['token_budget' => 1])->save();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('The specialist never gets this far.')
        ->willRespondWith('I could not complete that.');

    $parentRun = $this->runParent();
    $child = $this->childOf($parentRun);

    expect($child)->not->toBeNull()
        ->and($child->state)->toBe(RunState::TimedOut)
        ->and($child->error_class)->toBe(BudgetExceeded::class);

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()
        ->where('run_id', $parentRun->getKey())
        ->where('tool_name', 'delegate_to_agent')
        ->firstOrFail();

    expect($execution->result['content'])->toContain('shared budget')
        ->and($execution->result['content'])->toContain('Delegating again will not help')
        ->and($execution->result['data']['budget_exhausted'])->toBeTrue();
});
