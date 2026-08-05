<?php

declare(strict_types=1);

use Pandora\Pandora\Approvals\Approval;
use Pandora\Pandora\Approvals\ApprovalManager;
use Pandora\Pandora\Audit\AuditLog;
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

/**
 * Phase 2 acceptance criterion 34 — everything attempted is recorded, whether
 * or not it succeeded.
 *
 * The audit log is the answer to "what did the agent try to do?", which is a
 * question asked after something has gone wrong. A log that records only
 * successes cannot answer it.
 */
uses(MakesTools::class);

function auditActions(string $runId): array
{
    return AuditLog::query()
        ->where('run_id', $runId)
        ->orderBy('created_at')
        ->pluck('action')
        ->all();
}

beforeEach(function (): void {
    RefundOrderTool::$refunds = [];
    $this->registerTools([LookupOrderTool::class, RefundOrderTool::class]);
    $this->agentAllows(['lookup_order', 'refund_order']);
});

it('records a request and its execution', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('Shipped.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    expect(auditActions((string) $run->getKey()))
        ->toContain('tool.requested')
        ->toContain('tool.executed');
});

it('records a refusal, which is the entry that matters most', function (): void {
    $this->agentAllows([]);
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('I cannot.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    expect(auditActions((string) $run->getKey()))->toContain('tool.denied');
});

it('records an approval from request through to decision', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 4200,
        ])])
        ->willRespondWith('Refunded.');

    $run = $this->runToolAgent('Refund ORD-1234.');

    expect(auditActions((string) $run->getKey()))->toContain('approval.requested');

    $approval = Approval::query()->where('run_id', $run->getKey())->firstOrFail();
    app(ApprovalManager::class)->approve($approval, null, authorize: false);

    expect(auditActions((string) $run->getKey()))
        ->toContain('approval.approved')
        ->toContain('tool.executed');
});

it('records a denial with its comment', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 4200,
        ])])
        ->willRespondWith('Understood.');

    $run = $this->runToolAgent('Refund ORD-1234.');
    $approval = Approval::query()->where('run_id', $run->getKey())->firstOrFail();

    $this->fakeProvider()->reset()->willRespondWith('Understood.');
    app(ApprovalManager::class)->deny($approval, null, 'Outside the returns window.', authorize: false);

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'approval.denied')->firstOrFail();

    expect($entry->metadata['comment'])->toBe('Outside the returns window.')
        ->and($entry->metadata['tool'])->toBe('refund_order');
});

it('records an expiry distinctly from a denial', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234', 'amount_minor' => 4200,
        ])])
        ->willRespondWith('Refunded.');

    $run = $this->runToolAgent('Refund ORD-1234.');

    $approval = Approval::query()->where('run_id', $run->getKey())->firstOrFail();
    $approval->forceFill(['expires_at' => now()->subMinute()])->save();

    app(ApprovalManager::class)->expireOverdue();

    expect(auditActions((string) $run->getKey()))->toContain('approval.expired');
});

it('records an argument modification as its own entry, with the diff', function (): void {
    app()->instance(ToolPolicy::class, new class implements ToolPolicy
    {
        public function evaluate(Tool $tool, ToolInput $input, ToolContext $context): PolicyDecision
        {
            return PolicyDecision::modifyArguments(
                [...$input->toArray(), 'reference' => 'ORD-CLAMPED'],
                'Forced onto the tenant order.',
            );
        }
    });
    app()->forgetInstance(ToolGatekeeper::class);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('Shipped.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    /** @var AuditLog $entry */
    $entry = AuditLog::query()
        ->where('run_id', $run->getKey())
        ->where('action', 'tool.arguments_modified')
        ->firstOrFail();

    expect($entry->metadata['diff'])->toBe([
        ['field' => 'reference', 'from' => 'ORD-1234', 'to' => 'ORD-CLAMPED'],
    ])->and($entry->metadata['reason'])->toBe('Forced onto the tenant order.');
});

it('records the tool, the risk and the sanitized arguments on every entry', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('Shipped.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    /** @var AuditLog $requested */
    $requested = AuditLog::query()
        ->where('run_id', $run->getKey())
        ->where('action', 'tool.requested')
        ->firstOrFail();

    expect($requested->metadata['tool'])->toBe('lookup_order')
        ->and($requested->metadata['risk'])->toBe('low')
        ->and($requested->metadata['arguments'])->toBe(['reference' => 'ORD-1234']);
});

it('ties every entry to the run it belongs to', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'lookup_order', ['reference' => 'ORD-1234'])])
        ->willRespondWith('Shipped.');

    $run = $this->runToolAgent('Where is ORD-1234?');

    expect(AuditLog::query()->where('run_id', $run->getKey())->count())
        ->toBeGreaterThanOrEqual(3);
});
