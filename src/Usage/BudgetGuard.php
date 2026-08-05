<?php

declare(strict_types=1);

namespace Pandora\Pandora\Usage;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Exceptions\BudgetExceeded;
use Pandora\Pandora\Runs\Run;

/**
 * Stops a run BEFORE it spends money it does not have.
 *
 * The ordering is the whole point. A budget checked after the response is an
 * accounting record: it tells you what you have already been charged. This is
 * checked before the request leaves, so the last call a budget permits is the
 * one that reaches the limit, not the one that exceeds it.
 *
 * Scopes are checked narrowest first so the failure names the limit closest
 * to somebody who can act on it.
 *
 * Unpriced models contribute nothing to a COST budget -- their cost is null,
 * not zero, and SUM ignores it. That is deliberate: inventing a number for an
 * unpriced model would make a cost budget stop runs on the strength of a
 * figure nobody entered. Token budgets are unaffected and are the right tool
 * where prices are unknown.
 */
final class BudgetGuard
{
    public function __construct(
        private readonly Config $config,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws BudgetExceeded
     */
    public function assert(Run $run, Agent $agent): void
    {
        foreach (BudgetScope::ordered() as $scope) {
            $limits = $this->limitsFor($scope, $agent);

            if ($limits === []) {
                continue;
            }

            $this->assertScope($run, $agent, $scope, $limits);
        }
    }

    /**
     * @param array{tokens?: int|null, cost_minor?: int|null} $limits
     */
    private function assertScope(Run $run, Agent $agent, BudgetScope $scope, array $limits): void
    {
        $tokenLimit = $limits['tokens'] ?? null;
        $costLimit = $limits['cost_minor'] ?? null;

        if ($tokenLimit === null && $costLimit === null) {
            return;
        }

        $query = $this->scopedQuery($run, $agent, $scope);

        if ($query === null) {
            return;
        }

        if ($tokenLimit !== null) {
            $spent = (int) $query->clone()->sum('total_tokens');

            if ($spent >= $tokenLimit) {
                $this->fail($run, $scope, 'token', $tokenLimit, $spent);
            }
        }

        if ($costLimit !== null) {
            $spentMicro = (int) $query->clone()->sum('cost_micro');
            $spentMinor = (int) round($spentMicro / 10_000);

            if ($spentMinor >= $costLimit) {
                $this->fail($run, $scope, 'cost', $costLimit, $spentMinor);
            }
        }
    }

    /**
     * @return Builder<UsageRecord>|null
     */
    private function scopedQuery(Run $run, Agent $agent, BudgetScope $scope): ?Builder
    {
        $since = $this->periodStart();

        $query = match ($scope) {
            // A run's own spend is not periodic -- a run IS the period.
            BudgetScope::Run => UsageRecord::query()->where('run_id', $run->getKey()),

            BudgetScope::Conversation => $run->conversation_id === null
                ? null
                : UsageRecord::query()->where('conversation_id', $run->conversation_id),

            BudgetScope::Agent => UsageRecord::query()->where('agent_id', $agent->getKey()),

            BudgetScope::Tenant => $run->tenant_id === null
                ? null
                : UsageRecord::query()->where('tenant_id', $run->tenant_id),

            // Deliberately across every tenant -- a deployment-wide budget
            // that silently became per-tenant would be no protection at all.
            // The scope is dropped explicitly rather than through
            // acrossAllTenants(), which returns an untyped builder.
            BudgetScope::Global => UsageRecord::query()->withoutGlobalScope('pandora_tenant'),
        };

        if ($query === null) {
            return null;
        }

        if ($since !== null && $scope !== BudgetScope::Run) {
            $query->where('occurred_at', '>=', $since);
        }

        return $query;
    }

    /**
     * The limits for a scope: the agent row for run scope, configuration for
     * the rest.
     *
     * @return array{tokens?: int|null, cost_minor?: int|null}
     */
    private function limitsFor(BudgetScope $scope, Agent $agent): array
    {
        if ($scope === BudgetScope::Run) {
            return array_filter([
                'tokens' => $agent->token_budget,
                'cost_minor' => $agent->cost_budget_minor,
            ], static fn (?int $value): bool => $value !== null);
        }

        /** @var array{tokens?: int|null, cost_minor?: int|null} $limits */
        $limits = $this->config->get('pandora.budgets.'.$scope->value, []);

        return array_filter($limits, static fn (mixed $value): bool => $value !== null);
    }

    private function periodStart(): ?Carbon
    {
        /** @var string $period */
        $period = $this->config->get('pandora.budgets.period', 'month');

        return match ($period) {
            'day' => Carbon::now()->startOfDay(),
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            default => null,
        };
    }

    private function fail(Run $run, BudgetScope $scope, string $limitName, int $limit, int $spent): never
    {
        $this->audit->record(
            action: 'budget.exceeded',
            targetType: Run::class,
            targetId: (string) $run->getKey(),
            runId: (string) $run->getKey(),
            severity: 'warning',
            metadata: [
                'scope' => $scope->value,
                'limit_type' => $limitName,
                'limit' => $limit,
                'spent' => $spent,
            ],
        );

        throw BudgetExceeded::spend($scope->label(), $limitName, $limit, $spent);
    }
}
