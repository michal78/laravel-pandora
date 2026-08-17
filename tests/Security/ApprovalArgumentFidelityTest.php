<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Approvals\Approval;
use Pandora\Contracts\ToolPolicy;
use Pandora\Providers\Data\ToolCall;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\PolicyDecision;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolExecution;
use Pandora\Tools\ToolGatekeeper;
use Pandora\Tools\ToolInput;
use Pandora\UI\Livewire\ApprovalsIndex;

/**
 * Phase 9, criterion 1 -- T1's fourth clause: **the approval UI shows the real
 * arguments.**
 *
 * The other three clauses had tests. This one had the pieces of a test and no
 * test. `ToolPolicyTest` proves a modifying policy records a diff on the
 * decision trace; `UI/ApprovalsPageTest` proves an argument key is visible to a
 * user holding `tools.io.view` and invisible to one without it. Neither asks
 * the question T1 actually poses.
 *
 * That question is about *fidelity*, and it only has teeth when the requested
 * arguments and the effective ones differ. If a card can show `90000` while
 * `1000` executes -- or the reverse -- then approval is theatre: the human
 * authorised a call that never happened and a different one ran with their
 * name on it. Every layer can be correct and the system still be unsafe,
 * because the one human in the loop was shown the wrong thing.
 *
 * A prompt injection reaches this by the front door. It cannot change the
 * policy, but it chooses the arguments the policy then modifies, so the
 * requested and effective values differing is the *normal* case under attack
 * rather than an exotic one.
 */
uses(MakesTools::class);

function bindClampingPolicy(int $clampTo): void
{
    app()->instance(ToolPolicy::class, new class($clampTo) implements ToolPolicy
    {
        public function __construct(private readonly int $clampTo) {}

        public function evaluate(Tool $tool, ToolInput $input, ToolContext $context): PolicyDecision
        {
            if ($tool->name() !== 'refund_order') {
                return PolicyDecision::allow();
            }

            return PolicyDecision::modifyArguments(
                [...$input->toArray(), 'amount_minor' => $this->clampTo],
                'Clamped to the desk limit.',
            );
        }
    });

    // The gatekeeper resolves its policy once, at construction.
    app()->forgetInstance(ToolGatekeeper::class);
}

beforeEach(function (): void {
    RefundOrderTool::$refunds = [];
    $this->registerTools([RefundOrderTool::class]);
    $this->agentAllows(['refund_order']);

    bindClampingPolicy(1000);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 90000,
        ])])
        ->willRespondWith('Done.');

    $this->pausedRun = $this->runToolAgent('Refund order ORD-1234 in full.');

    /** @var Approval $approval */
    $approval = Approval::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();
    $this->approval = $approval;
});

it('records the arguments that will execute, not the ones that were asked for', function (): void {
    expect($this->approval->sanitized_arguments['amount_minor'])->toBe(1000);
});

it('shows the approver the value that will execute, and says what changed', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);
    Gate::define('pandora.tools.io.view', static fn (): bool => true);
    $this->actingAsUser();

    Livewire::test(ApprovalsIndex::class)
        ->call('select', (string) $this->approval->getKey())
        ->assertSee('1000')
        // 90000 IS on the card, and should be. The first draft of this test
        // asserted it was absent and failed -- correctly. The requested value
        // belongs there, under a heading that says a rule changed it; hiding
        // it would leave the approver unable to see that the model wanted
        // ninety times more. What must not happen is 90000 standing where the
        // effective arguments go, unlabelled.
        ->assertSee('A policy changed these arguments')
        ->assertSee('90000')
        // And the effective block specifically holds the clamped value. Seeing
        // "1000" somewhere on the page is satisfied by the diff's own right
        // hand side, so this pins the JSON the approver reads as the call.
        ->assertSee('"amount_minor": 1000');
});

it('tells the approver the call was changed at all', function (): void {
    // Showing the effective value alone is not enough. "Refund 1000" reads as
    // a call somebody meant to make; the diff is what tells the approver a
    // rule intervened and that the model wanted something else.
    //
    // Asserted field by field rather than as one array, because the key ORDER
    // of a JSON column is not a property any database guarantees. The first
    // version compared the whole structure with `toBe()` and was green on
    // SQLite and red on MySQL, which stores a JSON object with its keys sorted
    // by length -- `to`, `from`, `field`. Nothing about this criterion depends
    // on the order, so nothing about the test should either.
    $changes = $this->approval->proposed_modifications;

    expect($changes)->toHaveCount(1)
        ->and($changes[0]['field'])->toBe('amount_minor')
        ->and($changes[0]['from'])->toBe(90000)
        ->and($changes[0]['to'])->toBe(1000);
});

it('executes exactly what the card showed once it is approved', function (): void {
    // The other half of fidelity, and the one that makes the first half worth
    // asserting: approval must not be a checkpoint the real arguments walk
    // around. What was displayed is what runs.
    $shown = $this->approval->sanitized_arguments;

    Gate::define('pandora.approvals.resolve', static fn (): bool => true);
    $this->actingAsUser();

    Livewire::test(ApprovalsIndex::class)
        ->call('select', (string) $this->approval->getKey())
        ->call('approve', (string) $this->approval->getKey(), 'once');

    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->findOrFail($this->approval->tool_execution_id);

    expect($execution->arguments['amount_minor'])->toBe($shown['amount_minor'])
        ->and(RefundOrderTool::$refunds)->toHaveCount(1)
        ->and(RefundOrderTool::$refunds[0]['amount'])->toBe(1000);
});
