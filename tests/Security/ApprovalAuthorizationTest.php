<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pandora\Approvals\Approval;
use Pandora\Approvals\ApprovalManager;
use Pandora\Approvals\Enums\ApprovalKind;
use Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Core\Actor\ActorContext;
use Pandora\Exceptions\AuthorizationDenied;
use Pandora\Providers\Data\ToolCall;
use Pandora\Tests\Fixtures\TestUser;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tests\Support\MakesTools;

/**
 * Phase 2 acceptance criterion 20 — who may decide.
 *
 * An approval gate that anyone can resolve is decoration. The check lives in
 * the manager rather than only in the UI, so an API, a console command and a
 * Livewire component all get it without having to remember to.
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

    $run = $this->runToolAgent('Refund order ORD-1234.');
    $this->approval = Approval::query()->where('run_id', $run->getKey())->firstOrFail();
});

it('refuses a resolver without the approvals ability', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => false);

    expect(fn () => app(ApprovalManager::class)->approve(
        $this->approval,
        ActorContext::forUser($this->toolUser()),
    ))->toThrow(AuthorizationDenied::class);

    expect(Approval::query()->findOrFail($this->approval->getKey())->status)
        ->toBe(ApprovalStatus::Pending)
        ->and(RefundOrderTool::$refunds)->toBe([]);
});

it('allows a resolver who holds it', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);

    $resolved = app(ApprovalManager::class)->approve(
        $this->approval,
        ActorContext::forUser($this->toolUser()),
    );

    expect($resolved->status)->toBe(ApprovalStatus::Approved);
});

it('refuses a denial from someone who may not decide, too', function (): void {
    // Denying is a decision like any other: it resolves the request and
    // unblocks the run, so it needs the same authority.
    Gate::define('pandora.approvals.resolve', static fn (): bool => false);

    expect(fn () => app(ApprovalManager::class)->deny(
        $this->approval,
        ActorContext::forUser($this->toolUser()),
    ))->toThrow(AuthorizationDenied::class);
});

it('refuses a system actor with no user behind it', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);

    expect(fn () => app(ApprovalManager::class)->approve(
        $this->approval,
        ActorContext::system('automation'),
    ))->toThrow(AuthorizationDenied::class);
});

it('refuses an anonymous resolution outright', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);

    expect(fn () => app(ApprovalManager::class)->approve($this->approval, null))
        ->toThrow(AuthorizationDenied::class);
});

it('checks the ability against the resolver, not the logged-in user', function (): void {
    // A queue worker has nobody logged in, and an API call is whoever holds
    // the token. Neither can rely on the ambient guard.
    $permitted = TestUser::create([
        'name' => 'Supervisor', 'email' => 'supervisor@example.test', 'password' => 'secret',
    ]);

    Gate::define(
        'pandora.approvals.resolve',
        static fn (TestUser $user): bool => $user->email === 'supervisor@example.test',
    );

    $this->actingAs($this->toolUser());

    expect(fn () => app(ApprovalManager::class)->approve(
        $this->approval,
        ActorContext::forUser($this->toolUser()),
    ))->toThrow(AuthorizationDenied::class);

    expect(app(ApprovalManager::class)->approve(
        $this->approval->fresh(),
        ActorContext::forUser($permitted),
    )->status)->toBe(ApprovalStatus::Approved);
});

it('lets the requester answer their own confirmation, but nobody else', function (): void {
    // A confirmation is "did you mean that?" -- the person who asked answers
    // it. Anybody else answering for them is approving, and needs the ability.
    Gate::define('pandora.approvals.resolve', static fn (): bool => false);

    $requester = $this->toolUser();

    $this->approval->forceFill([
        'kind' => ApprovalKind::Confirmation->value,
        'requested_by_type' => $requester::class,
        'requested_by_id' => (string) $requester->getKey(),
    ])->save();

    $stranger = TestUser::create([
        'name' => 'Stranger', 'email' => 'stranger@example.test', 'password' => 'secret',
    ]);

    expect(fn () => app(ApprovalManager::class)->approve(
        $this->approval->fresh(),
        ActorContext::forUser($stranger),
    ))->toThrow(AuthorizationDenied::class);

    expect(app(ApprovalManager::class)->approve(
        $this->approval->fresh(),
        ActorContext::forUser($requester),
    )->status)->toBe(ApprovalStatus::Approved);
});
