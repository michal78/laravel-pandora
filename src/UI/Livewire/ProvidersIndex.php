<?php

declare(strict_types=1);

namespace Pandora\Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Pandora\Pandora\Providers\Catalog\CatalogModel;
use Pandora\Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Pandora\Providers\Credentials\CredentialManager;
use Pandora\Pandora\Providers\Credentials\ProviderCredential;
use Pandora\Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Pandora\Providers\ProviderManager;
use Pandora\Pandora\UI\PandoraGate;

/**
 * What this deployment can talk to, whether it is answering, and what it
 * charges.
 *
 * Gated on `pandora.providers.manage` rather than plain access: the page names
 * every model an agent may route to and every credential scope that exists,
 * which together describe the deployment's whole reach.
 *
 * No credential VALUE is loaded, anywhere. The page shows a fingerprint and a
 * last-four hint -- enough to tell two keys apart, never enough to use one.
 */
final class ProvidersIndex extends Component
{
    public function mount(): void
    {
        PandoraGate::authorize('providers.manage');
    }

    public function render(
        ProviderManager $providers,
        ProviderHealthMonitor $health,
        ModelCatalog $catalog,
        CredentialManager $credentials,
    ): View {
        $models = $catalog->all()->groupBy('provider_key');
        $stored = $credentials->stored()->groupBy('provider_key');

        $connections = [];

        foreach ($providers->configuredKeys() as $key) {
            /** @var array<string, mixed> $configured */
            $configured = (array) config("pandora.providers.connections.{$key}", []);

            $connections[] = [
                'key' => $key,
                'adapter' => is_string($configured['adapter'] ?? null) ? $configured['adapter'] : 'unknown',
                'base_url' => is_string($configured['base_url'] ?? null) ? $configured['base_url'] : null,
                'is_default' => $key === $providers->default(),
                'health' => $health->status($key),
                'models' => $models->get($key, collect()),
                'credentials' => $stored->get($key, collect()),
                // Whether a credential exists at all, which is the question an
                // operator is actually asking. The value itself is never read.
                'has_credential' => $credentials->resolve($key) !== null,
            ];
        }

        return view('pandora::livewire.providers-index', [
            'connections' => $connections,
            'staleModels' => $catalog->withStalePricing(),
            'staleAfterDays' => $catalog->staleAfterDays(),
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
