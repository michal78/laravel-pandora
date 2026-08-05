<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Pandora\Approvals\Approval;
use Pandora\Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\UI\Livewire\ApprovalsIndex;

/**
 * Phase 2 acceptance criteria 20, 29 and 31 — the Approvals page.
 *
 * The page is a convenience over ApprovalManager, never the boundary: every
 * refusal asserted here is enforced again underneath.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    RefundOrderTool::$refunds = [];
    $this->registerTools([RefundOrderTool::class]);
    $this->agentAllows(['refund_order']);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 4200,
        ])])
        ->willRespondWith('Done.');

    $this->pausedRun = $this->runToolAgent('Refund order ORD-1234.');
    $this->approval = Approval::query()->where('run_id', $this->pausedRun->getKey())->firstOrFail();
});

it('lists what is waiting, with the human summary', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);
    $this->actingAsUser();

    Livewire::test(ApprovalsIndex::class)
        ->assertOk()
        ->assertSee('refund_order')
        ->assertSee('ORD-1234')
        ->assertSee('High');
});

it('denies a user without access', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(ApprovalsIndex::class)->assertForbidden();
});

it('offers no decision buttons to a user who may not resolve', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(ApprovalsIndex::class)
        ->assertSee('Not yours to decide')
        ->assertDontSee('Approve once');
});

it('approves, and the run finishes', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);
    $this->actingAsUser();

    Livewire::test(ApprovalsIndex::class)
        ->call('select', (string) $this->approval->getKey())
        ->set('comment', 'Checked with the customer.')
        ->call('approve', (string) $this->approval->getKey(), 'once');

    /** @var Approval $resolved */
    $resolved = Approval::query()->findOrFail($this->approval->getKey());

    expect($resolved->status)->toBe(ApprovalStatus::Approved)
        ->and($resolved->comment)->toBe('Checked with the customer.')
        ->and(RefundOrderTool::$refunds)->toHaveCount(1)
        ->and(Run::query()->findOrFail($this->pausedRun->getKey())->state)
        ->toBe(RunState::Completed);
});

it('denies, and the tool never runs', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);
    $this->actingAsUser();
    $this->fakeProvider()->reset()->willRespondWith('Understood.');

    Livewire::test(ApprovalsIndex::class)
        ->call('deny', (string) $this->approval->getKey());

    expect(Approval::query()->findOrFail($this->approval->getKey())->status)
        ->toBe(ApprovalStatus::Denied)
        ->and(RefundOrderTool::$refunds)->toBe([]);
});

it('refuses a resolution the manager would refuse, and says so', function (): void {
    // The page must not be able to do what the manager forbids.
    Gate::define('pandora.approvals.resolve', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(ApprovalsIndex::class)
        ->call('approve', (string) $this->approval->getKey())
        ->assertSee('not authorized');

    expect(Approval::query()->findOrFail($this->approval->getKey())->status)
        ->toBe(ApprovalStatus::Pending)
        ->and(RefundOrderTool::$refunds)->toBe([]);
});

it('reports a race rather than pretending it worked', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);
    $this->actingAsUser();

    $component = Livewire::test(ApprovalsIndex::class)
        ->call('approve', (string) $this->approval->getKey());

    $component->call('approve', (string) $this->approval->getKey())
        ->assertSee('already been resolved');

    expect(RefundOrderTool::$refunds)->toHaveCount(1);
});

it('hides the raw arguments from a user without tools.io.view', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);
    Gate::define('pandora.tools.io.view', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(ApprovalsIndex::class)
        ->call('select', (string) $this->approval->getKey())
        // The summary is safe and stays; the argument dump does not.
        ->assertSee('ORD-1234')
        ->assertDontSee('amount_minor');
});

it('shows the arguments to a user who may read them', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);
    Gate::define('pandora.tools.io.view', static fn (): bool => true);
    $this->actingAsUser();

    Livewire::test(ApprovalsIndex::class)
        ->call('select', (string) $this->approval->getKey())
        ->assertSee('amount_minor');
});

it('hides the remembered option where a deployment disabled it', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);
    config()->set('pandora.approvals.allow_remembered', false);
    $this->actingAsUser();

    Livewire::test(ApprovalsIndex::class)
        ->call('select', (string) $this->approval->getKey())
        ->assertSee('Approve once')
        ->assertDontSee('Approve and remember');
});
