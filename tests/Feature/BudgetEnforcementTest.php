<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Pandora\Agents\AgentRunner;
use Pandora\Audit\AuditLog;
use Pandora\Core\Tenancy\TenantContext;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Exceptions\BudgetExceeded;
use Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\Data\UsageData;
use Pandora\Runs\Enums\RunState;
use Pandora\Tests\Support\MakesRuns;
use Pandora\Usage\UsageRecord;

uses(MakesRuns::class);

/**
 * Phase 3 acceptance criteria 28, 29 and 30 -- every scope, enforced before
 * the money is spent.
 */

/**
 * A usage record from earlier, so a budget has something to have consumed.
 */
function spend(array $attributes): UsageRecord
{
    /** @var UsageRecord $record */
    $record = UsageRecord::query()->create(array_merge([
        'provider_key' => 'fake',
        'model_key' => 'fake-model',
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_tokens' => 0,
        'requests' => 1,
        'occurred_at' => Carbon::now(),
    ], $attributes));

    return $record;
}

it('stops a run that has reached its own token budget mid-run', function (): void {
    // A budget of one token: the first turn is allowed, because it is the one
    // that REACHES the limit, and the continuation after the tool result is
    // where the budget bites.
    $agent = $this->makeAgent([
        'token_budget' => 1,
        'tool_policy' => ['allow' => ['inspect_run_status']],
    ]);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'inspect_run_status')], 'Checking.')
        ->willRespondWith('Never reached.');

    $run = app(AgentRunner::class)->agent($agent)->run('Hello');

    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_class)->toBe(BudgetExceeded::class);
});

it('lets a run finish inside its own token budget', function (): void {
    $agent = $this->makeAgent(['token_budget' => 100_000]);

    $this->fakeProvider()->willRespondWith('Done.', new UsageData(inputTokens: 900, outputTokens: 200));

    expect(app(AgentRunner::class)->agent($agent)->run('Hello')->state)->toBe(RunState::Completed);
});

it('stops a run before the provider is called at all', function (): void {
    $agent = $this->makeAgent(['token_budget' => 100]);

    spend(['run_id' => null, 'agent_id' => $agent->getKey(), 'total_tokens' => 500]);

    config()->set('pandora.budgets.agent', ['tokens' => 100]);

    $this->fakeProvider()->willRespondWith('Never sent.');

    $run = app(AgentRunner::class)->agent($agent)->run('Hello');

    // A budget checked after the response is an accounting record. This one
    // stopped the request from leaving.
    expect($run->state)->toBe(RunState::TimedOut)
        ->and($this->fakeProvider()->receivedRequests())->toBe([]);
});

it('terminates the run as timed_out with a specific reason', function (): void {
    $agent = $this->makeAgent();

    config()->set('pandora.budgets.agent', ['tokens' => 100]);
    spend(['agent_id' => $agent->getKey(), 'total_tokens' => 500]);

    $run = app(AgentRunner::class)->agent($agent)->run('Hello');

    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_class)->toBe(BudgetExceeded::class)
        // Named scope and figures, not "budget exceeded".
        ->and($run->error_message)->toContain('this agent')
        ->and($run->error_message)->toContain('100');
});

it('audits a budget breach', function (): void {
    $agent = $this->makeAgent();

    config()->set('pandora.budgets.agent', ['tokens' => 100]);
    spend(['agent_id' => $agent->getKey(), 'total_tokens' => 500]);

    $run = app(AgentRunner::class)->agent($agent)->run('Hello');

    $audit = AuditLog::query()->where('action', 'budget.exceeded')->firstOrFail();

    expect($audit->run_id)->toBe($run->getKey())
        ->and($audit->metadata['scope'])->toBe('agent')
        ->and($audit->metadata['limit'])->toBe(100)
        ->and($audit->metadata['spent'])->toBe(500);
});

it('enforces a conversation budget', function (): void {
    config()->set('pandora.budgets.conversation', ['tokens' => 100]);

    $agent = $this->makeAgent();
    $conversation = $this->makeConversation($agent);

    spend(['conversation_id' => $conversation->getKey(), 'total_tokens' => 200]);

    $run = app(AgentRunner::class)->agent($agent)->inConversation($conversation)->run('Hello');

    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_message)->toContain('this conversation');
});

it('enforces a tenant budget without touching another tenant', function (): void {
    config()->set('pandora.budgets.tenant', ['tokens' => 100]);

    $tenants = app(TenantManager::class);

    $acme = $tenants->with(new TenantContext('acme'), function (): array {
        $agent = $this->makeAgent();
        spend(['tenant_id' => 'acme', 'agent_id' => $agent->getKey(), 'total_tokens' => 500]);

        return ['agent' => $agent, 'run' => app(AgentRunner::class)->agent($agent)->run('Hello')];
    });

    expect($acme['run']->state)->toBe(RunState::TimedOut);

    $globex = $tenants->with(new TenantContext('globex'), function () {
        $this->fakeProvider()->willRespondWith('Plenty of budget here.');

        return app(AgentRunner::class)->agent($this->makeAgent())->run('Hello');
    });

    expect($globex->state)->toBe(RunState::Completed);
});

it('enforces a deployment-wide budget across every tenant', function (): void {
    config()->set('pandora.budgets.global', ['tokens' => 100]);

    $tenants = app(TenantManager::class);

    $tenants->with(new TenantContext('acme'), function (): void {
        spend(['tenant_id' => 'acme', 'total_tokens' => 500]);
    });

    // A global budget that silently became per-tenant would be no protection
    // at all.
    $run = $tenants->with(new TenantContext('globex'), fn () => app(AgentRunner::class)
        ->agent($this->makeAgent())
        ->run('Hello'));

    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_message)->toContain('this deployment');
});

it('enforces a cost budget in minor units', function (): void {
    config()->set('pandora.budgets.agent', ['cost_minor' => 500]);

    $agent = $this->makeAgent();

    // $6.00, in micro units.
    spend(['agent_id' => $agent->getKey(), 'cost_micro' => 6_000_000]);

    $run = app(AgentRunner::class)->agent($agent)->run('Hello');

    expect($run->state)->toBe(RunState::TimedOut)
        ->and($run->error_message)->toContain('cost');
});

it('lets an unpriced model past a cost budget, and a token budget catch it', function (): void {
    config()->set('pandora.budgets.agent', ['cost_minor' => 1]);

    $agent = $this->makeAgent();

    // Unpriced: cost is null, and SUM ignores it. Inventing a number here
    // would stop runs on the strength of a figure nobody entered.
    spend(['agent_id' => $agent->getKey(), 'cost_micro' => null, 'total_tokens' => 10_000]);

    $this->fakeProvider()->willRespondWith('Allowed.');

    expect(app(AgentRunner::class)->agent($agent)->run('Hello')->state)->toBe(RunState::Completed);

    config()->set('pandora.budgets.agent', ['tokens' => 1_000]);

    expect(app(AgentRunner::class)->agent($agent)->run('Hello')->state)->toBe(RunState::TimedOut);
});

it('counts only the configured period', function (): void {
    config()->set('pandora.budgets.period', 'month');
    config()->set('pandora.budgets.agent', ['tokens' => 100]);

    $agent = $this->makeAgent();

    spend([
        'agent_id' => $agent->getKey(),
        'total_tokens' => 5_000,
        'occurred_at' => Carbon::now()->subMonths(2),
    ]);

    $this->fakeProvider()->willRespondWith('Last month is last month.');

    expect(app(AgentRunner::class)->agent($agent)->run('Hello')->state)->toBe(RunState::Completed);
});

it('reports the narrowest breached scope', function (): void {
    config()->set('pandora.budgets.conversation', ['tokens' => 100]);
    config()->set('pandora.budgets.global', ['tokens' => 100]);

    $agent = $this->makeAgent();
    $conversation = $this->makeConversation($agent);

    spend(['conversation_id' => $conversation->getKey(), 'total_tokens' => 500]);

    $run = app(AgentRunner::class)->agent($agent)->inConversation($conversation)->run('Hello');

    // Both are breached. The one named is the one somebody can act on.
    expect($run->error_message)->toContain('this conversation');
});

it('does nothing when no budget is configured', function (): void {
    app(ModelCatalog::class)->seedFromConfig([]);

    $this->fakeProvider()->willRespondWith('No limits here.');

    expect(app(AgentRunner::class)->agent($this->makeAgent())->run('Hello')->state)
        ->toBe(RunState::Completed);
});
