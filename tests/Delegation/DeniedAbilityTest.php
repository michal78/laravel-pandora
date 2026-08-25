<?php

declare(strict_types=1);

use Pandora\Agents\Agent;
use Pandora\Delegation\AbilityIntersection;
use Pandora\Providers\Data\ToolCall;
use Pandora\Tests\Fixtures\Tools\CountingTool;
use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesDelegations;
use Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Tools\ToolExecution;

/**
 * Phase 9, T8 -- the half of layer 2 that delegation never exercised.
 *
 * `AbilityIntersection::abilitiesOfAgent()` resolves an agent's tools as
 * "granted, minus denied", and `Agent::deniedTools()` says why the second half
 * exists: *"Denial beats the allowlist, so a whole group can be granted with
 * one member carved out."*
 *
 * Every existing T8 test gives the parent an ability it simply **lacks** -- the
 * parent's allowlist does not mention `refund_order`, so the intersection drops
 * it. None gives the parent an ability it was explicitly **denied**. Removing
 * the deny half of that filter leaves all 70 delegation tests, and the whole
 * suite, green -- verified by removing it.
 *
 * The distinction is not academic, and the escalation runs the wrong way round
 * from the one the other tests guard:
 *
 *   - `abilitiesOf($parent, ...)` falls back to `abilitiesOfAgent($parentAgent)`
 *     for a top-level run. Ignore the deny list there and the PARENT is
 *     credited with a tool an operator explicitly took away from it.
 *   - That inflated set is what the child intersects against, so the child
 *     receives it.
 *   - At call time the gatekeeper checks the CHILD agent's policy and the
 *     frozen intersection. Neither mentions the parent's deny list.
 *
 * So a tool carved out of a parent by name would be reachable by delegating to
 * an agent that allows it -- one hop, exactly the failure T8 exists to prevent,
 * through the one door the suite was not watching.
 */
uses(MakesDelegations::class);

beforeEach(function (): void {
    $this->registerDelegationTools([LookupOrderTool::class, RefundOrderTool::class, CountingTool::class]);
    CountingTool::$calls = 0;
});

/**
 * Grant broadly and carve one tool out by name -- the shape `deniedTools()`
 * documents, and the shape no delegation test used.
 */
function carveOut(object $test, string $denied, array $childTools): array
{
    [$parent, $child] = $test->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order', 'refund_order', 'counting_tool'],
        childTools: $childTools,
    );

    $parent->forceFill([
        'tool_policy' => [
            'allow' => ['delegate_to_agent', 'lookup_order', 'refund_order', 'counting_tool'],
            'deny' => [$denied],
        ],
    ])->save();

    return [$parent->refresh(), $child];
}

it('withholds from the child a tool the parent was explicitly denied', function (): void {
    [, $child] = carveOut($this, 'lookup_order', ['lookup_order']);

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Specialist answer.')
        ->willRespondWith('Done.');

    $childRun = $this->childOf($this->runParent());

    // Both allowlists name it. Only the parent's deny list keeps it out.
    expect($childRun->effective_tools)->not->toContain('lookup_order');
});

it('refuses the call itself, not merely the stored list', function (): void {
    // The list being right is necessary and not sufficient: what matters is
    // that the gatekeeper acts on it when the child actually reaches. A tool
    // with a visible side effect, because a containment failure fails WIDE
    // rather than loudly -- the call succeeds, and only the counter says so.
    [, $child] = carveOut($this, 'counting_tool', ['counting_tool']);

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRequestTools([new ToolCall('call_c', 'counting_tool', ['label' => 'x'])])
        ->willRespondWith('I could not do that.')
        ->willRespondWith('Sorry, that could not be done.');

    $childRun = $this->childOf($this->runParent());

    /** @var ToolExecution $attempt */
    $attempt = ToolExecution::query()
        ->where('run_id', $childRun->getKey())
        ->where('tool_name', 'counting_tool')
        ->firstOrFail();

    expect($attempt->status->value)->toBe('denied')
        ->and($attempt->decided_by)->toBe(AuthorizationLayer::Agent->value)
        ->and(CountingTool::$calls)->toBe(0);
});

it('names the carved-out tool in the withheld list an operator reads', function (): void {
    [$parent, $child] = carveOut($this, 'lookup_order', ['lookup_order']);

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Specialist answer.')
        ->willRespondWith('Done.');

    $parentRun = $this->runParent();

    $withheld = app(AbilityIntersection::class)->withheld($parentRun, $parent, $child);

    // "The child agent is configured for X and was refused it" is the line
    // that explains a delegate behaving less capably than its own config says.
    expect($withheld)->toContain('lookup_order');
});

it('withholds a tool denied on the child agent, with the parent holding it', function (): void {
    // The other direction. The parent may look things up; the child agent is
    // granted it and then carved out, so the intersection must still drop it.
    [$parent, $child] = $this->makeDelegationPair(
        parentTools: ['delegate_to_agent', 'lookup_order'],
        childTools: ['lookup_order'],
    );

    $child->forceFill([
        'tool_policy' => ['allow' => ['lookup_order'], 'deny' => ['lookup_order']],
    ])->save();

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Specialist answer.')
        ->willRespondWith('Done.');

    $childRun = $this->childOf($this->runParent());

    expect($childRun->effective_tools)->not->toContain('lookup_order');
});

it('still passes on a tool neither side denied', function (): void {
    // Without this the four above are satisfied by an intersection that
    // withholds everything, which would pass while granting nothing.
    [, $child] = carveOut($this, 'refund_order', ['lookup_order', 'refund_order']);

    $this->fakeProvider()
        ->willRequestTools([$this->delegateCall()])
        ->willRespondWith('Specialist answer.')
        ->willRespondWith('Done.');

    $childRun = $this->childOf($this->runParent());

    expect($childRun->effective_tools)->toContain('lookup_order')
        ->and($childRun->effective_tools)->not->toContain('refund_order');
});
