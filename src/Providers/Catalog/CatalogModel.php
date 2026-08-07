<?php

declare(strict_types=1);

namespace Pandora\Providers\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pandora\Providers\Data\ProviderCapabilities;
use Pandora\Providers\Data\UsageData;
use Pandora\Support\Concerns\PandoraModel;

/**
 * One model in the catalog: what it can do, how big it is, and what it costs.
 *
 * Deployment-wide rather than per-tenant. A tenant does not get its own
 * private version of what GPT-4o can do; what a tenant gets is a restriction
 * on which of these rows it may route to, and that lives with the tenant.
 *
 * @property string $id
 * @property string $provider_key
 * @property string $model_key
 * @property string|null $display_name
 * @property int|null $context_limit
 * @property int|null $max_output_tokens
 * @property bool $supports_streaming
 * @property bool $supports_tools
 * @property bool $supports_structured_output
 * @property bool $supports_vision
 * @property bool $supports_audio
 * @property bool $supports_embeddings
 * @property string|null $input_price_per_million
 * @property string|null $output_price_per_million
 * @property string|null $cached_input_price_per_million
 * @property string|null $cache_write_price_per_million
 * @property string $currency
 * @property string|null $pricing_source
 * @property Carbon|null $pricing_date
 * @property Carbon|null $deprecated_at
 * @property bool $enabled
 * @property Carbon|null $synced_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CatalogModel extends Model
{
    use PandoraModel;

    protected string $pandoraTable = 'models';

    /** @var list<string> */
    protected $fillable = [
        'provider_key', 'model_key', 'display_name',
        'context_limit', 'max_output_tokens',
        'supports_streaming', 'supports_tools', 'supports_structured_output',
        'supports_vision', 'supports_audio', 'supports_embeddings',
        'input_price_per_million', 'output_price_per_million',
        'cached_input_price_per_million', 'cache_write_price_per_million',
        'currency', 'pricing_source', 'pricing_date',
        'deprecated_at', 'enabled', 'synced_at', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_limit' => 'integer',
            'max_output_tokens' => 'integer',
            'supports_streaming' => 'boolean',
            'supports_tools' => 'boolean',
            'supports_structured_output' => 'boolean',
            'supports_vision' => 'boolean',
            'supports_audio' => 'boolean',
            'supports_embeddings' => 'boolean',
            'pricing_date' => 'date',
            'deprecated_at' => 'datetime',
            'synced_at' => 'datetime',
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function reference(): string
    {
        return $this->provider_key.'/'.$this->model_key;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            streaming: $this->supports_streaming,
            tools: $this->supports_tools,
            structuredOutput: $this->supports_structured_output,
            vision: $this->supports_vision,
            audio: $this->supports_audio,
            embeddings: $this->supports_embeddings,
        );
    }

    /**
     * Whether this model satisfies everything the request needs.
     *
     * Only requested capabilities are checked: a request that needs nothing
     * special is satisfied by anything.
     */
    public function satisfies(ProviderCapabilities $required): bool
    {
        foreach ($required->jsonSerialize() as $capability => $needed) {
            if ($needed === true && ($this->capabilities()->jsonSerialize()[$capability] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    public function isUsable(): bool
    {
        return $this->enabled && ($this->deprecated_at === null || $this->deprecated_at->isFuture());
    }

    public function isPriced(): bool
    {
        return $this->input_price_per_million !== null || $this->output_price_per_million !== null;
    }

    /**
     * Pricing that has not been reviewed within the configured window is
     * flagged rather than quietly trusted.
     */
    public function pricingIsStale(int $staleAfterDays): bool
    {
        if (! $this->isPriced()) {
            return false;
        }

        return $this->pricing_date === null
            || $this->pricing_date->diffInDays(Carbon::now()) > $staleAfterDays;
    }

    /**
     * What a call using this model cost.
     *
     * Returns null for an unpriced model. That is deliberate: zero would sum
     * into a total that looks like a fact, and the operator would never learn
     * their catalog has no prices in it.
     */
    public function estimate(UsageData $usage, int $staleAfterDays = 90): ?CostEstimate
    {
        if (! $this->isPriced()) {
            return null;
        }

        $input = (float) ($this->input_price_per_million ?? 0);
        $output = (float) ($this->output_price_per_million ?? 0);

        // Cached input is billed at its own (lower) rate where one is set;
        // where it is not, it is billed as ordinary input rather than free.
        $cachedInput = $this->cached_input_price_per_million !== null
            ? (float) $this->cached_input_price_per_million
            : $input;

        $cacheWrite = $this->cache_write_price_per_million !== null
            ? (float) $this->cache_write_price_per_million
            : $input;

        $amount = ($usage->inputTokens * $input)
            + ($usage->outputTokens * $output)
            + ($usage->cachedInputTokens * $cachedInput)
            + ($usage->cachedOutputTokens * $cacheWrite);

        return new CostEstimate(
            // Prices are per MILLION tokens; the result is in micro units, so
            // the two factors of a million cancel and no precision is lost.
            amountMicro: (int) round($amount),
            currency: $this->currency,
            source: $this->pricing_source,
            pricedAt: $this->pricing_date?->toDateTimeImmutable(),
            stale: $this->pricingIsStale($staleAfterDays),
        );
    }
}
