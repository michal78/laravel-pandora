<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRunner;
use Pandora\Exceptions\BudgetExceeded;
use Pandora\Providers\Adapters\FakeProvider;
use Pandora\Providers\Data\ToolCall;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\Tools\CountingTool;
use Pandora\Tests\Fixtures\Tools\SlowTool;
use Pandora\Tests\Support\MakesRuns;
use Pandora\Tests\Support\MakesTools;
use Pandora\Usage\UsageRecord;

/**
 * Phase 9, T7 -- each limit halts a run, and halts it for its own reason.
 *
 * The criterion words its ablation backwards from the usual one: not "remove
 * this limit and watch its test fail", but *"each proved by removing the OTHER
 * limits"*. That is the right question here, because `assertWithinBudget()`
 * checks four limits in a fixed order -- iterations, tool calls, duration,
 * tokens -- and the first one to trip is the one that throws. A run built to
 * exhaust its tool calls usually exhausts its iterations at the same moment,
 * and a test asserting only "it stopped, with a BudgetExceeded" cannot tell
 * which of the two did it. Every such test passes with the limit it names
 * deleted, as long as a neighbour trips first.
 *
 * So every test here sets **every other limit generously** and asserts the
 * message of the limit under test. `BudgetExceeded` carries a `limit`
 * discriminator and a message naming the figure; the existing suite asserted
 * neither, which is what let two limits go unnoticed:
 *
 *   - **the wall-clock limit had no test at all.** `Run::hasExceededDeadline()`
 *     has one call site in `src/` and none in `tests/`; deleting the check left
 *     all 1,828 tests green. The test that reads like its coverage --
 *     "terminates the run as timed_out with a specific reason" -- is about the
 *     agent-scope TOKEN budget. `RunState::TimedOut` is the state every budget
 *     breach lands in, so the name means "stopped by a limit", not "ran out of
 *     time", and the one limit that literally runs out of time had nothing.
 *
 *   - **the agent token check is redundant with the scoped one.** Deleting it
 *     also left the suite green, because `BudgetGuard::limitsFor(Run)` reads the
 *     same `token_budget` column by a different route and catches it. Recorded
 *     rather than removed: the two are not equivalent for a delegated run,
 *     where `budgetOwner()` charges the tree's root agent.
 */
uses(MakesRuns::class, MakesTools::class);

/**
 * An agent that will not stop for any reason except the one under test.
 *
 * @param array<string, mixed> $overrides
 */
function unlimitedExcept(object $test, array $overrides): Agent
{
    return $test->makeAgent(array_merge([
        'max_iterations' => 25,
        'max_tool_calls' => 25,
        'max_duration_seconds' => 3_600,
        'token_budget' => null,
        'cost_budget_minor' => null,
        'tool_policy' => ['allow' => ['slow_tool', 'counting_tool']],
    ], $overrides));
}

/**
 * Queue `$count` tool-call turns, each asking for something different.
 *
 * The arguments have to vary. `counting_tool` called twice with one label is a
 * DUPLICATE call, and the duplicate limit would stop the run before either the
 * iteration or the tool-call limit was reached -- one limit standing in for
 * another, which is the exact confusion this file exists to remove.
 */
function keepCallingTools(FakeProvider $provider, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $provider->willRequestTools(
            [new ToolCall('call_'.$i, 'counting_tool', ['label' => 'turn-'.$i])],
            'Working.',
        );
    }

    $provider->willRespondWith('Never reached.');
}

function runAgent(object $test, Agent $agent): Run
{
    // The actor matters. Tool authorization is against the ACTOR, so a run
    // dispatched with nobody attached has every tool call DENIED -- which still
    // burns an iteration and a tool call, so a limit test built that way still
    // "passes" while never executing a tool at all. That is how the wall-clock
    // test failed first time round: the tool that moves the clock never ran.
    return app(AgentRunner::class)
        ->agent($agent)
        ->forUser($test->toolUser())
        ->inConversation($test->makeConversation($agent))
        ->run('Go.');
}

beforeEach(function (): void {
    SlowTool::$advanceSeconds = 0;
    SlowTool::$widenAgentDurationTo = null;
    CountingTool::$calls = 0;
    $this->registerTools([SlowTool::class, CountingTool::class]);

    // No scoped budgets, so nothing outside the agent row can do the halting
    // and be mistaken for the limit under test.
    config()->set('pandora.budgets', []);
});

afterEach(function (): void {
    // A tool moved the clock. Leaving it moved would age every later test's
    // timestamps by two minutes.
    Carbon::setTestNow();
    SlowTool::$advanceSeconds = 0;
    SlowTool::$widenAgentDurationTo = null;
});

it('halts on the wall-clock limit and says so', function (): void {
    SlowTool::$advanceSeconds = 120;

    $agent = unlimitedExcept($this, ['max_duration_seconds' => 30]);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'slow_tool', [])], 'Working.')
        ->willRespondWith('Never reached.');

    $run = runAgent($this, $agent);

    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_class)->toBe(BudgetExceeded::class)
        // Named, not merely "a budget". With 25 iterations and 25 tool calls
        // available this run had no other limit left to hit.
        ->and($run->error_message)->toContain('wall-clock')
        ->and($run->error_message)->toContain('30s');
});

it('lets a run that stays inside its deadline finish', function (): void {
    // Without this the check above could be a blanket refusal of any run that
    // calls a tool, and still pass.
    SlowTool::$advanceSeconds = 5;

    $agent = unlimitedExcept($this, ['max_duration_seconds' => 3_600]);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'slow_tool', [])], 'Working.')
        ->willRespondWith('Finished in time.');

    $run = runAgent($this, $agent);

    expect($run->state)->toBe(RunState::Completed)
        ->and($run->output)->toBe('Finished in time.');
});

it('does not let a stamped deadline be extended by editing the agent mid-run', function (): void {
    // `deadline_at` is stamped at creation precisely so that widening
    // `max_duration_seconds` afterwards cannot rescue a run that is already
    // over. The comment in RunFactory says so; nothing asserted it.
    SlowTool::$advanceSeconds = 120;
    // Widened from inside the run, which is the only place an operator's edit
    // can actually land mid-flight.
    SlowTool::$widenAgentDurationTo = 86_400;

    $agent = unlimitedExcept($this, ['max_duration_seconds' => 30]);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'slow_tool', [])], 'Working.')
        ->willRespondWith('Never reached.');

    $run = runAgent($this, $agent);

    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_message)->toContain('wall-clock')
        // The message quotes the agent's CURRENT value, but the decision was
        // made against the stamped deadline -- so the run stopped even though
        // the agent now says a day.
        ->and($run->deadline_at->lessThan($run->created_at->addMinutes(5)))->toBeTrue()
        ->and($agent->refresh()->max_duration_seconds)->toBe(86_400);
});

it('halts on the iteration limit and says so', function (): void {
    // Two iterations, and a provider that keeps asking for one more tool.
    $agent = unlimitedExcept($this, ['max_iterations' => 2]);

    keepCallingTools($this->fakeProvider(), 6);

    $run = runAgent($this, $agent);

    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_message)->toContain('iterations')
        ->and($run->error_message)->toContain('2');
});

it('halts on the tool-call limit and says so, with iterations left over', function (): void {
    // The pairing that makes attribution matter. Tool calls run out first only
    // because iterations were left generous; with both tight, whichever is
    // checked first wins and the message is the only way to tell which.
    $agent = unlimitedExcept($this, ['max_iterations' => 25, 'max_tool_calls' => 2]);

    keepCallingTools($this->fakeProvider(), 6);

    $run = runAgent($this, $agent);

    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_message)->toContain('tool calls')
        ->and($run->error_message)->toContain('2');
});

it('halts on the agent token budget and says so', function (): void {
    $agent = unlimitedExcept($this, ['token_budget' => 1]);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'counting_tool', ['label' => 'x'])], 'Checking.')
        ->willRespondWith('Never reached.');

    $run = runAgent($this, $agent);

    // The exact phrasing matters here, and this is the one assertion in the
    // file that had to be tightened twice. `token_budget` is enforced in TWO
    // places that read the same column: `assertWithinBudget()` compares the
    // run's own counters, and `BudgetGuard`'s Run scope sums usage records via
    // `budgetOwner()`. Both fire, `assertWithinBudget()` first. Asserting
    // merely "token budget" and "1" matched the scoped message too --
    // "The token budget for this run is 1 and N has been used." -- so the test
    // passed with the run-level check deleted. Only `BudgetExceeded::tokens()`
    // phrases it this way.
    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_message)->toContain('exceeded its token budget of 1');
});

it('still halts on the token budget when only the scoped guard is left', function (): void {
    // The other half of the redundancy, asserted rather than assumed. Deleting
    // the run-level check does NOT open a hole -- `BudgetGuard` catches it and
    // says so in its own words. Defence in depth, and both layers now named.
    $agent = unlimitedExcept($this, ['token_budget' => null]);

    config()->set('pandora.budgets.agent', ['tokens' => 100]);

    $this->fakeProvider()->willRespondWith('Never sent.');

    $run = runAgent($this, $agent);

    expect($run->state)->toBe(RunState::Completed);

    // With spend on record, the scoped guard stops the next one.
    UsageRecord::query()->create([
        'agent_id' => $agent->getKey(),
        'provider_key' => 'fake',
        'model_key' => 'fake-model',
        'input_tokens' => 500,
        'output_tokens' => 0,
        'total_tokens' => 500,
        'cost_micro' => 0,
        'currency' => 'USD',
        'occurred_at' => now(),
    ]);

    $this->fakeProvider()->willRespondWith('Never sent either.');

    $second = runAgent($this, $agent);

    expect($second->state)->toBe(RunState::TimedOut)
        ->and($second->error_message)->toContain('token budget for');
});
