<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Routing;

use Illuminate\Contracts\Config\Repository as Config;
use Pandora\Pandora\Contracts\ModelRouter;
use Pandora\Pandora\Exceptions\NoModelAvailable;
use Pandora\Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Pandora\Providers\ProviderManager;

/**
 * Routing you can read (ADR-0006).
 *
 * Precedence, highest first:
 *
 *     explicit call -> run override -> conversation override
 *                   -> agent default -> configured default
 *
 * and then, on failure, the agent's fallback chain in the order it is written.
 * There is no optimiser. Building one before there is production data to
 * optimise against produces a system whose behaviour nobody can predict and
 * whose "wrong" choices nobody can explain.
 *
 * Candidates are dropped for four reasons, and every drop is reported rather
 * than silently swallowed:
 *
 *  1. the tenant is not permitted this model,
 *  2. the provider is not configured,
 *  3. the provider is degraded,
 *  4. the model lacks a capability the request needs.
 *
 * Tenant restrictions are applied to the CANDIDATE SET, before anything else,
 * so a fallback chain cannot walk out of them.
 */
final class DeterministicModelRouter implements ModelRouter
{
    public function __construct(
        private readonly Config $config,
        private readonly ProviderManager $providers,
        private readonly ModelCatalog $catalog,
        private readonly ProviderHealthMonitor $health,
    ) {}

    public function resolve(RoutingRequest $request): RoutingDecision
    {
        $skipped = [];

        foreach ($this->candidates($request) as [$providerKey, $modelKey, $source]) {
            $reference = $providerKey.'/'.$modelKey;

            // Already tried and watched fail in this iteration.
            if (in_array($reference, $request->excluded, true)) {
                continue;
            }

            $rejection = $this->reject($request, $providerKey, $modelKey);

            if ($rejection !== null) {
                $skipped[] = "{$reference}: {$rejection}";

                continue;
            }

            return new RoutingDecision(
                providerKey: $providerKey,
                modelKey: $modelKey,
                // A candidate reached only because an earlier one failed is a
                // fallback, whatever precedence level it was written at.
                source: $request->excluded === [] ? $source : RoutingSource::Fallback,
                attempt: count($request->excluded) + 1,
                skipped: $skipped,
            );
        }

        throw NoModelAvailable::forAgent($request->agent->slug, $skipped);
    }

    /**
     * The ordered candidate list: precedence first, then the fallback chain.
     *
     * @return list<array{0: string, 1: string, 2: RoutingSource}>
     */
    private function candidates(RoutingRequest $request): array
    {
        $agent = $request->agent;

        /** @var string $configProvider */
        $configProvider = $this->config->get('pandora.providers.default', 'fake');
        /** @var string $configModel */
        $configModel = $this->config->get('pandora.models.default', 'fake-model');

        $defaultProvider = $agent->default_provider ?? $configProvider;

        $levels = [
            [$request->explicitProvider, $request->explicitModel, RoutingSource::Explicit],
            [$request->runProvider, $request->runModel, RoutingSource::Run],
            [$request->conversationProvider, $request->conversationModel, RoutingSource::Conversation],
            [$agent->default_provider, $agent->default_model, RoutingSource::Agent],
            [$configProvider, $configModel, RoutingSource::Config],
        ];

        $candidates = [];

        // Precedence yields exactly ONE primary choice: the highest level that
        // names a model. The levels below it are not alternatives -- an agent
        // with its own default has not asked to fall back to the deployment
        // default, and treating it as though it had would route runs to a
        // model nobody selected.
        foreach ($levels as [$provider, $model, $source]) {
            if ($model === null || $model === '') {
                continue;
            }

            $candidates[] = [$provider ?? $defaultProvider, $model, $source];

            break;
        }

        // The fallback chain. `provider/model` names a provider explicitly;
        // a bare model name stays on the agent's own provider.
        foreach ($agent->fallback_models ?? [] as $fallback) {
            if ($fallback === '') {
                continue;
            }

            $candidates[] = [
                ...$this->split($fallback, $defaultProvider),
                RoutingSource::Fallback,
            ];
        }

        return $this->withoutRepeats($candidates);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $reference, string $defaultProvider): array
    {
        $separator = strpos($reference, '/');

        return $separator === false
            ? [$defaultProvider, $reference]
            : [substr($reference, 0, $separator), substr($reference, $separator + 1)];
    }

    /**
     * A model named at two precedence levels is one candidate, not two. Left
     * in, the fallback chain would "retry" the model that just failed.
     *
     * @param list<array{0: string, 1: string, 2: RoutingSource}> $candidates
     * @return list<array{0: string, 1: string, 2: RoutingSource}>
     */
    private function withoutRepeats(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $reference = $candidate[0].'/'.$candidate[1];

            if (isset($seen[$reference])) {
                continue;
            }

            $seen[$reference] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * Why this candidate cannot be used, or null if it can.
     */
    private function reject(RoutingRequest $request, string $providerKey, string $modelKey): ?string
    {
        if (! $this->permittedForTenant($request->tenantId, $providerKey, $modelKey)) {
            return 'not permitted for this tenant';
        }

        if (! $this->providers->has($providerKey)) {
            return 'provider is not configured';
        }

        if (! $this->health->isUsable($providerKey)) {
            return 'provider is degraded';
        }

        $model = $this->catalog->find($providerKey, $modelKey);

        // A model absent from the catalog is allowed. The catalog is an
        // optional enrichment, and treating "we know nothing about it" as
        // "it cannot do that" would break every deployment that has not run
        // a sync.
        if ($model === null) {
            return null;
        }

        if (! $model->isUsable()) {
            return $model->enabled ? 'model is deprecated' : 'model is disabled';
        }

        if (! $model->satisfies($request->required)) {
            return 'model lacks a required capability';
        }

        if ($request->minimumContextTokens !== null
            && $model->context_limit !== null
            && $model->context_limit <= $request->minimumContextTokens) {
            return 'context window is no larger than the one that overflowed';
        }

        return null;
    }

    /**
     * Tenant model restrictions: an allowlist, applied before anything else.
     *
     * A tenant with no entry is unrestricted, which keeps single-tenant
     * deployments free of ceremony. `provider/*` permits a whole provider.
     */
    private function permittedForTenant(?string $tenantId, string $providerKey, string $modelKey): bool
    {
        if ($tenantId === null) {
            return true;
        }

        /** @var array<string, list<string>> $restrictions */
        $restrictions = $this->config->get('pandora.models.tenant_restrictions', []);

        $allowed = $restrictions[$tenantId] ?? null;

        if ($allowed === null) {
            return true;
        }

        return in_array($providerKey.'/'.$modelKey, $allowed, true)
            || in_array($providerKey.'/*', $allowed, true)
            || in_array($modelKey, $allowed, true);
    }
}
