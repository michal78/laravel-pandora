<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Pandora\Providers\Catalog\CatalogModel;
use Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Credentials\ProviderCredential;
use Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Providers\ProviderManager;
use Pandora\UI\PandoraGate;

/**
 * What this deployment can talk to, whether it is answering, and what it
 * charges.
 *
 * Two levels, like the Tools page. Anyone with `pandora.access` may see which
 * providers exist, whether they are answering and which models are available:
 * that is operational information, and a control center that refuses to tell
 * you why chat is broken is not much of a control center.
 *
 * `pandora.providers.manage` adds the parts that describe the deployment's
 * commercial and security posture -- which credentials are installed, at which
 * scopes, and what each model costs.
 *
 * No credential VALUE is loaded at either level. What an authorized operator
 * sees is a fingerprint: enough to tell two keys apart, never enough to use
 * one.
 */
final class ProvidersIndex extends Component
{
    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function render(
        ProviderManager $providers,
        ProviderHealthMonitor $health,
        ModelCatalog $catalog,
        CredentialManager $credentials,
    ): View {
        $canManage = PandoraGate::allows('providers.manage');

        $models = $catalog->all()->groupBy('provider_key');

        // Not loaded at all without the ability, rather than loaded and
        // hidden in the markup.
        $stored = $canManage
            ? $credentials->stored()->groupBy('provider_key')
            : collect();

        $connections = [];

        $staleAfterDays = $catalog->staleAfterDays();

        foreach ($providers->configuredKeys() as $key) {
            /** @var array<string, mixed> $configured */
            $configured = (array) config("pandora.providers.connections.{$key}", []);

            /** @var Collection<int, CatalogModel> $connectionModels */
            $connectionModels = $models->get($key, collect());

            $hasCredential = $credentials->resolve($key) !== null;
            $status = $health->status($key);

            $connections[] = [
                'key' => $key,
                'adapter' => is_string($configured['adapter'] ?? null) ? $configured['adapter'] : 'unknown',
                'base_url' => is_string($configured['base_url'] ?? null) ? $configured['base_url'] : null,
                'is_default' => $key === $providers->default(),
                'health' => $status,
                'models' => $connectionModels,
                'credentials' => $stored->get($key, collect()),
                // Whether a credential exists at all, which is the question
                // anybody debugging a broken provider is actually asking. The
                // value itself is never read.
                'has_credential' => $hasCredential,

                // Whether this connection stays shut when the page collapses
                // it. A closed row is a claim that nothing here needs you, and
                // the claim has to be true -- so a provider that is not
                // answering, holds no credential, or is charging against
                // prices nobody has confirmed opens itself and says which.
                'attention' => array_values(array_filter([
                    // `unknown` is not `broken`. A provider nobody has probed
                    // yet is the normal state of a fresh installation, and
                    // treating it as a problem would open every row on day one
                    // — which is the whole feature, undone. The summary badge
                    // still says Unknown.
                    in_array($status->status, ['degraded', 'unhealthy'], true) ? 'not answering' : null,
                    $hasCredential ? null : 'no credential',
                    $canManage && $connectionModels->contains(
                        static fn (CatalogModel $model): bool => $model->pricingIsStale($staleAfterDays),
                    ) ? 'stale pricing' : null,
                ])),
            ];
        }

        return view('pandora::livewire.providers-index', [
            'connections' => $connections,
            'canManage' => $canManage,
            'staleModels' => $canManage ? $catalog->withStalePricing() : collect(),
            'staleAfterDays' => $staleAfterDays,
        ])->layout('pandora::layouts.app', ['title' => 'Providers']);
    }

    /**
     * @return array<string, string>
     */
    public function credentialSummary(ProviderCredential $credential): array
    {
        return [
            'scope' => $credential->source()->label(),
            'fingerprint' => $credential->fingerprint,
        ];
    }

    public function priceOf(CatalogModel $model): string
    {
        if (! $model->isPriced()) {
            return 'unpriced';
        }

        return sprintf(
            '%s in / %s out per M %s',
            $model->input_price_per_million ?? '—',
            $model->output_price_per_million ?? '—',
            $model->currency,
        );
    }
}
