<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRunner;
use Pandora\Approvals\Approval;
use Pandora\Approvals\ApprovalManager;
use Pandora\Approvals\Enums\ApprovalKind;
use Pandora\Approvals\Enums\ApprovalScope;
use Pandora\Approvals\Enums\ApprovalStatus;
use Pandora\Audit\AuditLog;
use Pandora\Audit\AuditLogger;
use Pandora\Conversations\Conversation;
use Pandora\Messages\Message;
use Pandora\Runs\Run;
use Pandora\Runs\RunStep;
use Pandora\Tests\Support\MakesRuns;
use Pandora\Tools\Enums\RiskLevel;

uses(MakesRuns::class);

/**
 * Acceptance guarantee 15 -- tenant isolation.
 *
 * This is the guarantee that separates Pandora from the single-operator agent
 * daemons it takes its capability list from. If any of these fail, the whole
 * premise fails.
 */
it('stamps the current tenant on every record it creates', function (): void {
    $agent = inTenant('acme', fn () => $this->makeAgent());

    expect($agent->tenant_id)->toBe('acme');
});

it('hides another tenant\'s agents, conversations, runs, messages and steps', function (): void {
    $acme = inTenant('acme', function (): array {
        $agent = $this->makeAgent();
        $conversation = $this->makeConversation($agent);
        $run = $this->makeRun(['agent_id' => $agent->getKey(), 'conversation_id' => $conversation->getKey()]);

        Message::query()->create([
            'conversation_id' => $conversation->getKey(),
            'role' => 'user', 'type' => 'text', 'sequence' => 1, 'content' => 'acme secret',
        ]);

        RunStep::query()->create([
            'run_id' => $run->getKey(), 'sequence' => 1,
            'type' => 'model_request', 'status' => 'succeeded', 'started_at' => now(),
        ]);

        return compact('agent', 'conversation', 'run');
    });

    inTenant('globex', function () use ($acme): void {
        expect(Agent::query()->count())->toBe(0)
            ->and(Conversation::query()->count())->toBe(0)
            ->and(Run::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0)
            ->and(RunStep::query()->count())->toBe(0);

        // The leak that matters most: a direct lookup by a known id.
        expect(Agent::query()->find($acme['agent']->getKey()))->toBeNull()
            ->and(Conversation::query()->find($acme['conversation']->getKey()))->toBeNull()
            ->and(Run::query()->find($acme['run']->getKey()))->toBeNull();
    });
});

it('still sees its own records', function (): void {
    $agent = inTenant('acme', fn () => $this->makeAgent());

    inTenant('acme', function () use ($agent): void {
        expect(Agent::query()->find($agent->getKey()))->not->toBeNull();
    });
});

it('applies no scope for a single-tenant application', function (): void {
    $agent = $this->makeAgent();

    expect($agent->tenant_id)->toBeNull()
        ->and(Agent::query()->find($agent->getKey()))->not->toBeNull();
});

it('requires an explicit, greppable opt-out to cross tenants', function (): void {
    $agent = inTenant('acme', fn () => $this->makeAgent());

    inTenant('globex', function () use ($agent): void {
        expect(Agent::query()->find($agent->getKey()))->toBeNull()
            ->and(Agent::acrossAllTenants()->find($agent->getKey()))->not->toBeNull();
    });
});

it('keeps runs isolated across tenants when executed', function (): void {
    $this->fakeProvider()->willRespondWith('ok');

    inTenant('acme', function (): void {
        $conversation = $this->makeConversation();

        app(AgentRunner::class)
            ->agent($conversation->agent)
            ->inConversation($conversation)
            ->run('Hello');
    });

    inTenant('globex', function (): void {
        expect(Run::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0);
    });

    inTenant('acme', function (): void {
        expect(Run::query()->count())->toBe(1);
    });
});

it('does not let one tenant resolve another tenant\'s approval', function (): void {
    // Phase 9 audit, 2026-08-19: removing `use BelongsToTenant;` from
    // `Approval` left all 1,813 tests green. The write side hid it --
    // `ApprovalManager::request()` sets `tenant_id` from the run explicitly,
    // so the stamp survives losing the trait. What does not survive is the
    // read, and the read is the whole control here: `resolve()` fetches with
    // `Approval::query()->lockForUpdate()->findOrFail($id)`, so the global
    // scope is the only thing standing between a known approval id and
    // another tenant approving a destructive call with it.
    $approval = inTenant('acme', function (): Approval {
        $run = $this->makeRun();

        return Approval::query()->create([
            'run_id' => $run->getKey(),
            'tool_name' => 'refund_order',
            'tool_version' => '1.0.0',
            'risk_level' => RiskLevel::High->value,
            'summary' => 'Refund £42.00 to order 1234',
            'scope' => ApprovalScope::Once->value,
            'kind' => ApprovalKind::Approval->value,
            'status' => ApprovalStatus::Pending->value,
            'expires_at' => now()->addDay(),
        ]);
    });

    expect($approval->tenant_id)->toBe('acme');

    inTenant('globex', function () use ($approval): void {
        expect(Approval::query()->count())->toBe(0)
            ->and(Approval::query()->find($approval->getKey()))->toBeNull();

        // The one that decides something: not merely invisible on a list, but
        // unreachable by id through the method that grants the authority.
        expect(fn () => app(ApprovalManager::class)->approve($approval->getKey(), authorize: false))
            ->toThrow(ModelNotFoundException::class);
    });

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending);
});

it('does not show one tenant another tenant\'s audit log', function (): void {
    // Same audit, same result: `AuditLog` lost the trait and nothing went red.
    // An audit row is the record of who authorised what, and it carries the
    // actor, the target and the redacted arguments of a call -- reading
    // another tenant's is a disclosure whether or not anything can be written.
    $log = inTenant('acme', fn () => app(AuditLogger::class)->record(
        action: 'approval.requested',
        targetType: Approval::class,
        targetId: 'acme-approval',
        severity: 'notice',
        metadata: ['tool' => 'refund_order'],
    ));

    expect($log->tenant_id)->toBe('acme');

    inTenant('globex', function () use ($log): void {
        expect(AuditLog::query()->count())->toBe(0)
            ->and(AuditLog::query()->find($log->getKey()))->toBeNull();
    });

    inTenant('acme', function (): void {
        expect(AuditLog::query()->count())->toBe(1);
    });
});
