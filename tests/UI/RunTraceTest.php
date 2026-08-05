<?php

declare(strict_types=1);

use Livewire\Livewire;
use Pandora\Pandora\Approvals\Approval;
use Pandora\Pandora\Approvals\ApprovalManager;
use Pandora\Pandora\Contracts\ToolPolicy;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\Tools\PolicyDecision;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolGatekeeper;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\UI\Livewire\RunDetail;

/**
 * Phase 2 acceptance criteria 11 and 33 — the trace renders the tool and
 * approval steps, and an argument diff is visible without being hunted for.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    RefundOrderTool::$refunds = [];
    $this->registerTools([LookupOrderTool::class, RefundOrderTool::class]);
    $this->agentAllows(['lookup_order', 'refund_order']);
    $this->actingAsUser($this->toolUser());
});

it('renders the tool request and its result, in order', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('It shipped.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    $html = Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->assertOk()
        ->assertSee('Tool requested')
        ->assertSee('Tool result')
        ->html();

    expect(strpos($html, 'Tool requested'))->toBeLessThan(strpos($html, 'Tool result'));
});

it('renders the approval request and the response that resolved it', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 4200,
        ])])
        ->willRespondWith('Refunded.');

    $run = $this->runToolAgent('Refund ORD-1234.');

    Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->assertSee('Approval requested')
        ->assertSee('ORD-1234');

    $approval = Approval::query()
        ->where('run_id', $run->getKey())
        ->firstOrFail();

    app(ApprovalManager::class)
        ->approve($approval, null, authorize: false);

    Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->assertSee('Approval resolved')
        ->assertSee('Approved');
});

it('shows an argument diff openly, not behind a disclosure', function (): void {
    // A policy that silently rewrote what the model asked for is exactly what
    // a person reading a trace needs to see without looking for it.
    app()->instance(ToolPolicy::class, new class implements ToolPolicy
    {
        public function evaluate(Tool $tool, ToolInput $input, ToolContext $context): PolicyDecision
        {
            return $tool->name() === 'refund_order'
                ? PolicyDecision::modifyArguments(
                    [...$input->toArray(), 'amount_minor' => 1000],
                    'Clamped to the desk limit.',
                )
                : PolicyDecision::allow();
        }
    });
    app()->forgetInstance(ToolGatekeeper::class);

    $this->agentApprovalPolicy(['auto_approve' => ['refund_order']]);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 90000,
        ])])
        ->willRespondWith('Refunded.');

    $run = $this->runToolAgent('Refund 900.00 on ORD-1234.');

    $html = Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->assertSee('Arguments changed by policy')
        ->assertSee('amount_minor')
        ->assertSee('90000')
        ->assertSee('1000')
        ->html();

    // Not inside a <details>, so it is on screen rather than one click away.
    // Other steps do use disclosures, so the check is whether the last one
    // opened before the diff was also closed before it.
    $diffPosition = (int) strpos($html, 'Arguments changed by policy');
    $before = substr($html, 0, $diffPosition);
    $lastOpen = strrpos($before, '<details');

    expect($lastOpen === false || strpos($before, '</details>', $lastOpen) !== false)
        ->toBeTrue('The argument diff is hidden inside a disclosure.');

    // A modified call still pauses -- modification is not approval -- and the
    // clamp is what actually runs once a human agrees to it.
    $approval = Approval::query()
        ->where('run_id', $run->getKey())
        ->firstOrFail();

    expect($approval->proposed_modifications)->toBe([
        ['field' => 'amount_minor', 'from' => 90000, 'to' => 1000],
    ]);

    app(ApprovalManager::class)->approve($approval, null, authorize: false);

    expect(RefundOrderTool::$refunds)->toBe([['reference' => 'ORD-1234', 'amount' => 1000]]);
});

it('shows a denied call in the trace with the layer that refused it', function (): void {
    $this->agentAllows([]);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('I cannot.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->assertSee('Tool requested')
        ->assertSee('agent');
});

/**
 * Phase 3 acceptance criterion 22 — a routing hop is visible in the trace.
 */
it('renders which model was chosen, and why, before the request that used it', function (): void {
    $this->fakeProvider()->willRespondWith('Answered.');

    $run = $this->runToolAgent('Hello');

    $html = Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->assertOk()
        ->assertSee('Model routed')
        // The label carries the destination and the reason for it: "why is
        // this run on gpt-4o-mini?" is a question asked at the worst moment.
        ->assertSee('fake/fake-model')
        ->assertSee('Agent default')
        ->html();

    expect(strpos($html, 'Model routed'))->toBeLessThan(strpos($html, 'Model request'));
});
