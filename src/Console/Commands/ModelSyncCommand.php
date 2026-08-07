<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Contracts\ModelCatalogProvider;
use Pandora\Exceptions\Provider\ProviderException;
use Pandora\Providers\Catalog\CatalogModel;
use Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Providers\ProviderManager;

/**
 * Fill the catalog from configuration and from the providers themselves.
 *
 * Configuration first, then the providers, because a provider sync must never
 * be able to clear a price a human entered.
 */
final class ModelSyncCommand extends Command
{
    protected $signature = 'pandora:model:sync
                            {--provider= : Only this connection}
                            {--config-only : Seed from configuration without contacting any provider}';

    protected $description = 'Sync the Pandora model catalog';

    public function handle(ModelCatalog $catalog, ProviderManager $providers): int
    {
        $seeded = $catalog->seedFromConfig();

        $this->components->info($seeded === 0
            ? 'No models are configured under `models.catalog`; nothing seeded.'
            : "Seeded {$seeded} model(s) from configuration.");

        if ($this->option('config-only') !== true) {
            $this->syncProviders($catalog, $providers);
        }

        $this->report($catalog);

        return self::SUCCESS;
    }

    private function syncProviders(ModelCatalog $catalog, ProviderManager $providers): void
    {
        /** @var string|null $only */
        $only = $this->option('provider');

        foreach ($providers->configuredKeys() as $key) {
            if ($only !== null && $only !== $key) {
                continue;
            }

            try {
                $provider = $providers->provider($key);
            } catch (\Throwable $e) {
                $this->components->warn("[{$key}] could not be resolved: {$e->getMessage()}");

                continue;
            }

            if (! $provider instanceof ModelCatalogProvider) {
                $this->components->twoColumnDetail($key, '<fg=gray>no models endpoint</>');

                continue;
            }

            try {
                $seen = $catalog->syncFrom($provider);
                $this->components->twoColumnDetail($key, "<fg=green>{$seen} model(s)</>");
            } catch (ProviderException $e) {
                // One unreachable provider must not stop the others: a catalog
                // that is partly fresh is more useful than one that failed.
                $this->components->twoColumnDetail($key, '<fg=yellow>'.$e->getMessage().'</>');
            }
        }
    }

    private function report(ModelCatalog $catalog): void
    {
        $models = $catalog->all();

        if ($models->isEmpty()) {
            return;
        }

        $this->newLine();

        $this->table(
            ['Model', 'Context', 'Tools', 'Input /M', 'Output /M', 'Priced'],
            $models->map(static fn (CatalogModel $model): array => [
                $model->reference(),
                $model->context_limit === null ? '-' : number_format($model->context_limit),
                $model->supports_tools ? 'yes' : '-',
                $model->input_price_per_million ?? '-',
                $model->output_price_per_million ?? '-',
                $model->isPriced() ? ($model->pricing_date?->toDateString() ?? '-') : '<fg=gray>unpriced</>',
            ])->all(),
        );

        $stale = $catalog->withStalePricing();

        if ($stale->isNotEmpty()) {
            // Loud, because a stale price produces a cost report that looks
            // authoritative and is wrong.
            $this->components->warn(sprintf(
                '%d model(s) have pricing older than %d days: %s',
                $stale->count(),
                $catalog->staleAfterDays(),
                $stale->map(static fn (CatalogModel $m): string => $m->reference())->implode(', '),
            ));
        }
    }
}
