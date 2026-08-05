<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Approvals\Approval;
use Pandora\Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\UI\Livewire\Chat;

/**
 * Phase 2 acceptance criterion 32 — tool and approval cards in the thread.
 *
 * Reconstructed from the database on every render, so a reload shows exactly
 * what a live view showed. Nothing here depends on having seen a broadcast.
 */
uses(MakesTools::class);

function chatRun(string $input, ?Conversation $conversation = null): Run
{
    // Owned by the acting user, the way the chat page creates one: the
    // component refuses to render somebody else's conversation.
    $user = test()->toolUser();

    $conversation ??= test()->makeConversation(test()->agent(), [
        'created_by_type' => $user::class,
        'created_by_id' => (string) $user->getKey(),
    ]);

    test()->chatConversation = $conversation;

    return app(AgentRunner::class)
        ->agent(test()->agent())
        ->forUser(test()->toolUser())
        ->inConversation($conversation)
        ->run($input);
}

beforeEach(function (): void {
    RefundOrderTool::$refunds = [];
    $this->registerTools([LookupOrderTool::class, RefundOrderTool::class]);
    $this->agentAllows(['lookup_order', 'refund_order']);
    $this->actingAsUser($this->toolUser());
});

it('shows a tool result as a card rather than as conversation', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('It has shipped.');

    chatRun('Where is ORD-1234?');

    Livewire::test(Chat::class, ['conversation' => (string) $this->chatConversation->getKey()])
        ->assertSee('pd-tool-card', escape: false)
        ->assertSee('lookup_order')
        ->assertSee('Succeeded')
        ->assertSee('It has shipped.');
});

it('reconstructs the card from the database after a reload', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('It has shipped.');

    chatRun('Where is ORD-1234?');

    // A brand new component instance, having seen no broadcast at all.
    Livewire::test(Chat::class, ['conversation' => (string) $this->chatConversation->getKey()])
        ->assertSee('lookup_order')
        ->assertSee('Succeeded');
});

it('shows a denied call as denied, not as a silent absence', function (): void {
    $this->agentAllows([]);
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('I cannot look that up.');

    chatRun('Where is ORD-1234?');

    Livewire::test(Chat::class, ['conversation' => (string) $this->chatConversation->getKey()])
        ->assertSee('Denied')
        ->assertSee('not permitted');
});

it('hides raw arguments from a user without tools.io.view', function (): void {
    Gate::define('pandora.tools.io.view', static fn (): bool => false);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('It has shipped.');

    chatRun('Where is ORD-1234?');

    Livewire::test(Chat::class, ['conversation' => (string) $this->chatConversation->getKey()])
        ->assertDontSee('Arguments');
});

it('shows an approval card while a run waits, with what it will do', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 4200,
        ])])
        ->willRespondWith('Refunded.');

    chatRun('Refund ORD-1234.');

    Livewire::test(Chat::class, ['conversation' => (string) $this->chatConversation->getKey()])
        ->assertSee('42.00')
        ->assertSee('High risk');
});

it('lets an authorized user approve from the thread', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => true);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 4200,
        ])])
        ->willRespondWith('Refunded.');

    $run = chatRun('Refund ORD-1234.');

    /** @var Approval $approval */
    $approval = Approval::query()->where('run_id', $run->getKey())->firstOrFail();

    Livewire::test(Chat::class, ['conversation' => (string) $this->chatConversation->getKey()])
        ->call('approve', (string) $approval->getKey());

    expect(RefundOrderTool::$refunds)->toHaveCount(1)
        ->and(Run::query()->findOrFail($run->getKey())->state)->toBe(RunState::Completed);
});

it('offers no buttons to a user who may not resolve, and says why', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => false);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 4200,
        ])])
        ->willRespondWith('Refunded.');

    chatRun('Refund ORD-1234.');

    Livewire::test(Chat::class, ['conversation' => (string) $this->chatConversation->getKey()])
        ->assertSee('Waiting for someone who can approve')
        ->assertDontSee('wire:click="approve', escape: false);
});

it('refuses an approval the manager would refuse', function (): void {
    Gate::define('pandora.approvals.resolve', static fn (): bool => false);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 4200,
        ])])
        ->willRespondWith('Refunded.');

    $run = chatRun('Refund ORD-1234.');

    /** @var Approval $approval */
    $approval = Approval::query()->where('run_id', $run->getKey())->firstOrFail();

    Livewire::test(Chat::class, ['conversation' => (string) $this->chatConversation->getKey()])
        ->call('approve', (string) $approval->getKey())
        ->assertSee('not authorized');

    expect(Approval::query()->findOrFail($approval->getKey())->status)
        ->toBe(ApprovalStatus::Pending)
        ->and(RefundOrderTool::$refunds)->toBe([]);
});
