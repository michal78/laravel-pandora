<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Catalog;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Pandora\Pandora\Contracts\ModelCatalogProvider;
use Pandora\Pandora\Contracts\Provider;
use Pandora\Pandora\Exceptions\InvalidConfiguration;
use Pandora\Pandora\Providers\Data\UsageData;

/**
 * The model catalog: what this deployment may route to, and what it costs.
 *
 * Two sources feed it and neither overwrites the other's work. A provider
 * sync knows what models exist and how large they are; it knows nothing about
 * price. Configuration knows price, and is the only thing allowed to set one.
 * A sync therefore never clears a price a human entered, and a seed never
 * invents a capability a provider did not report.
 */
final class ModelCatalog
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function find(string $providerKey, string $modelKey): ?CatalogModel
    {
        return CatalogModel::query()
            ->where('provider_key', $providerKey)
            ->where('model_key', $modelKey)
            ->first();
    }

    /**
     * Every model this deployment may currently route to.
     *
     * @return Collection<int, CatalogModel>
     */
    public function usable(?string $providerKey = null): Collection
    {
        return CatalogModel::query()
            ->where('enabled', true)
            ->when($providerKey !== null, static fn ($query) => $query->where('provider_key', $providerKey))
            ->orderBy('provider_key')
            ->orderBy('model_key')
            ->get()
            ->filter(static fn (CatalogModel $model): bool => $model->isUsable())
            ->values();
    }

    /**
     * @return Collection<int, CatalogModel>
     */
    public function all(): Collection
    {
        return CatalogModel::query()->orderBy('provider_key')->orderBy('model_key')->get();
    }

    public function estimate(string $providerKey, string $modelKey, UsageData $usage): ?CostEstimate
    {
        return $this->find($providerKey, $modelKey)?->estimate($usage, $this->staleAfterDays());
    }

    /**
     * Seed from `pandora.models.catalog`.
     *
     * Configuration is authoritative for everything it states, because a
     * human put it there on purpose. It is the only path that may set a price,
     * and it must state a source and a date alongside one -- an unattributed
     * price is refused rather than stored, because six months later nobody can
     * tell whether it was ever right.
     *
     * @param list<array<string, mixed>>|null $definitions
     * @return int the number of rows written
     */
    public function seedFromConfig(?array $definitions = null): int
    {
        if ($definitions === null) {
            /** @var list<array<string, mixed>> $definitions */
            $definitions = $this->config->get('pandora.models.catalog', []);
        }

        $written = 0;

        foreach ($definitions as $definition) {
            $this->seedOne($definition);
            $written++;
        }

        return $written;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function seedOne(array $definition): CatalogModel
    {
        $providerKey = $this->requireString($definition, 'provider');
        $modelKey = $this->requireString($definition, 'key');

        $hasPrice = isset($definition['input_price']) || isset($definition['output_price']);

        if ($hasPrice && (! isset($definition['pricing_source']) || ! isset($definition['pricing_date']))) {
            throw new InvalidConfiguration(sprintf(
                'Model [%s/%s] states a price without a pricing_source and pricing_date. '
                .'An unattributed price cannot be checked later, so it is refused rather than stored.',
                $providerKey,
                $modelKey,
            ));
        }

        /** @var list<string> $capabilities */
        $capabilities = is_array($definition['capabilities'] ?? null) ? $definition['capabilities'] : [];

        $attributes = [
            'display_name' => $definition['display_name'] ?? null,
            'context_limit' => $definition['context_limit'] ?? null,
            'max_output_tokens' => $definition['max_output_tokens'] ?? null,
            'supports_streaming' => in_array('streaming', $capabilities, true),
            'supports_tools' => in_array('tools', $capabilities, true),
            'supports_structured_output' => in_array('structured_output', $capabilities, true),
            'supports_vision' => in_array('vision', $capabilities, true),
            'supports_audio' => in_array('audio', $capabilities, true),
            'supports_embeddings' => in_array('embeddings', $capabilities, true),
            'input_price_per_million' => $definition['input_price'] ?? null,
            'output_price_per_million' => $definition['output_price'] ?? null,
            'cached_input_price_per_million' => $definition['cached_input_price'] ?? null,
            'cache_write_price_per_million' => $definition['cache_write_price'] ?? null,
            'currency' => $definition['currency'] ?? 'USD',
            'pricing_source' => $definition['pricing_source'] ?? null,
            'pricing_date' => $definition['pricing_date'] ?? null,
            'deprecated_at' => $definition['deprecated_at'] ?? null,
            'enabled' => $definition['enabled'] ?? true,
        ];

        /** @var CatalogModel $model */
        $model = CatalogModel::query()->firstOrNew([
            'provider_key' => $providerKey,
            'model_key' => $modelKey,
        ]);

        $model->fill($attributes)->save();

        return $model;
    }

    /**
     * Sync what a provider itself reports.
     *
     * Pricing columns are untouched on purpose. No vendor exposes prices
     * through its API, so a sync that wrote to them could only ever be
     * clearing something a human had entered.
     *
     * @return int the number of models seen
     */
    public function syncFrom(Provider $provider): int
    {
        if (! $provider instanceof ModelCatalogProvider) {
            return 0;
        }

        $seen = 0;

        foreach ($provider->models() as $descriptor) {
            /** @var CatalogModel $model */
            $model = CatalogModel::query()->firstOrNew([
                'provider_key' => $descriptor->providerKey,
                'model_key' => $descriptor->modelKey,
            ]);

            $capabilities = $descriptor->capabilities;

            $model->fill(array_filter([
                'display_name' => $descriptor->displayName,
                'context_limit' => $descriptor->contextLimit,
                'max_output_tokens' => $descriptor->maxOutputTokens,
                'deprecated_at' => $descriptor->deprecatedAt,
                'metadata' => $descriptor->metadata === [] ? null : $descriptor->metadata,
            ], static fn (mixed $value): bool => $value !== null));

            if ($capabilities !== null) {
                $model->fill([
                    'supports_streaming' => $capabilities->streaming,
                    'supports_tools' => $capabilities->tools,
                    'supports_structured_output' => $capabilities->structuredOutput,
                    'supports_vision' => $capabilities->vision,
                    'supports_audio' => $capabilities->audio,
                    'supports_embeddings' => $capabilities->embeddings,
                ]);
            }

            $model->synced_at = Carbon::now();
            $model->save();

            $seen++;
        }

        return $seen;
    }

    /**
     * Priced models whose price has not been reviewed lately.
     *
     * Surfaced in the control center and by `pandora:status` rather than left
     * for somebody to discover from an invoice.
     *
     * @return Collection<int, CatalogModel>
     */
    public function withStalePricing(): Collection
    {
        $days = $this->staleAfterDays();

        return $this->all()->filter(
            static fn (CatalogModel $model): bool => $model->pricingIsStale($days),
        )->values();
    }

    public function staleAfterDays(): int
    {
        return (int) $this->config->get('pandora.models.pricing_stale_after_days', 90);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function requireString(array $definition, string $field): string
    {
        $value = $definition[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidConfiguration(
                "A model catalog entry is missing its [{$field}].",
            );
        }

        return $value;
    }
}
