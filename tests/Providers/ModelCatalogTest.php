<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Pandora\Pandora\Exceptions\InvalidConfiguration;
use Pandora\Pandora\Providers\Adapters\GeminiProvider;
use Pandora\Pandora\Providers\Adapters\OpenAiCompatibleProvider;
use Pandora\Pandora\Providers\Catalog\CatalogModel;
use Pandora\Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Pandora\Providers\Data\ProviderCapabilities;
use Pandora\Pandora\Providers\Data\UsageData;

/**
 * Phase 3 acceptance criterion 14 -- the catalog seeds, prices and flags
 * staleness.
 */
function catalog(): ModelCatalog
{
    return app(ModelCatalog::class);
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function pricedEntry(array $overrides = []): array
{
    return array_merge([
        'provider' => 'openai',
        'key' => 'gpt-4o-mini',
        'display_name' => 'GPT-4o mini',
        'context_limit' => 128_000,
        'max_output_tokens' => 16_384,
        'capabilities' => ['streaming', 'tools', 'structured_output', 'vision'],
        'input_price' => 0.15,
        'output_price' => 0.60,
        'cached_input_price' => 0.075,
        'currency' => 'USD',
        'pricing_source' => 'https://example.test/pricing',
        'pricing_date' => now()->toDateString(),
    ], $overrides);
}

it('seeds models from configuration', function (): void {
    config()->set('pandora.models.catalog', [pricedEntry()]);

    expect(catalog()->seedFromConfig())->toBe(1);

    $model = catalog()->find('openai', 'gpt-4o-mini');

    expect($model)->not->toBeNull()
        ->and($model->display_name)->toBe('GPT-4o mini')
        ->and($model->context_limit)->toBe(128_000)
        ->and($model->supports_tools)->toBeTrue()
        ->and($model->supports_audio)->toBeFalse()
        ->and($model->isPriced())->toBeTrue();
});

it('seeds idempotently rather than duplicating a model', function (): void {
    config()->set('pandora.models.catalog', [pricedEntry()]);

    catalog()->seedFromConfig();
    catalog()->seedFromConfig();

    expect(CatalogModel::query()->count())->toBe(1);
});

it('refuses a price with no source or date', function (): void {
    expect(fn () => catalog()->seedFromConfig([
        ['provider' => 'openai', 'key' => 'gpt-4o', 'input_price' => 2.50],
    ]))->toThrow(InvalidConfiguration::class, 'unattributed price');
});

it('accepts an unpriced model, which is a normal thing to have', function (): void {
    catalog()->seedFromConfig([
        ['provider' => 'ollama', 'key' => 'llama3.2', 'capabilities' => ['streaming', 'tools']],
    ]);

    $model = catalog()->find('ollama', 'llama3.2');

    expect($model?->isPriced())->toBeFalse()
        ->and($model?->estimate(new UsageData(inputTokens: 1_000)))->toBeNull();
});

it('estimates cost from token counts, without losing sub-cent precision', function (): void {
    config()->set('pandora.models.catalog', [pricedEntry()]);
    catalog()->seedFromConfig();

    // 1,000 input at $0.15/M and 500 output at $0.60/M is $0.00045, which
    // rounds to zero in cents. Micro units keep it.
    $estimate = catalog()->estimate('openai', 'gpt-4o-mini', new UsageData(
        inputTokens: 1_000,
        outputTokens: 500,
    ));

    expect($estimate)->not->toBeNull()
        ->and($estimate->amountMicro)->toBe(450)
        ->and($estimate->currency)->toBe('USD')
        ->and($estimate->source)->toBe('https://example.test/pricing')
        ->and($estimate->stale)->toBeFalse();
});

it('prices cached input at its own rate', function (): void {
    config()->set('pandora.models.catalog', [pricedEntry()]);
    catalog()->seedFromConfig();

    $estimate = catalog()->estimate('openai', 'gpt-4o-mini', new UsageData(
        cachedInputTokens: 1_000_000,
    ));

    expect($estimate?->amountMicro)->toBe(75_000);
});

it('bills cached input as ordinary input when no cached rate is configured', function (): void {
    config()->set('pandora.models.catalog', [pricedEntry(['cached_input_price' => null])]);
    catalog()->seedFromConfig();

    // Free would be the optimistic assumption, and it would understate every
    // bill on a provider that charges for cache reads.
    expect(catalog()->estimate('openai', 'gpt-4o-mini', new UsageData(cachedInputTokens: 1_000_000))?->amountMicro)
        ->toBe(150_000);
});

it('flags pricing that has not been reviewed inside the window', function (): void {
    config()->set('pandora.models.pricing_stale_after_days', 30);
    config()->set('pandora.models.catalog', [pricedEntry([
        'pricing_date' => now()->subDays(45)->toDateString(),
    ])]);

    catalog()->seedFromConfig();

    $model = catalog()->find('openai', 'gpt-4o-mini');

    expect($model?->pricingIsStale(30))->toBeTrue()
        ->and(catalog()->withStalePricing())->toHaveCount(1)
        // Still estimated -- the operator is told, not left without a number.
        ->and(catalog()->estimate('openai', 'gpt-4o-mini', new UsageData(inputTokens: 1_000_000))?->stale)
        ->toBeTrue();
});

it('does not call an unpriced model stale', function (): void {
    catalog()->seedFromConfig([['provider' => 'ollama', 'key' => 'llama3.2']]);

    expect(catalog()->withStalePricing())->toHaveCount(0);
});

it('syncs the models a provider reports', function (): void {
    Http::fake(['api.openai.test/*' => Http::response(['data' => [
        ['id' => 'gpt-4o-mini', 'object' => 'model'],
        ['id' => 'gpt-4o', 'object' => 'model'],
    ]])]);

    $provider = new OpenAiCompatibleProvider(
        key: 'openai',
        config: ['base_url' => 'https://api.openai.test/v1', 'api_key' => 'sk-test'],
        http: app(HttpFactory::class),
    );

    expect(catalog()->syncFrom($provider))->toBe(2)
        ->and(catalog()->find('openai', 'gpt-4o'))->not->toBeNull()
        ->and(catalog()->find('openai', 'gpt-4o')?->synced_at)->not->toBeNull();
});

it('records the real limits Gemini reports', function (): void {
    Http::fake(['generativelanguage.test/*' => Http::response(['models' => [[
        'name' => 'models/gemini-2.5-flash',
        'displayName' => 'Gemini 2.5 Flash',
        'inputTokenLimit' => 1_048_576,
        'outputTokenLimit' => 65_536,
        'supportedGenerationMethods' => ['generateContent', 'streamGenerateContent'],
    ]]])]);

    $provider = new GeminiProvider(
        key: 'gemini',
        config: ['base_url' => 'https://generativelanguage.test/v1beta', 'api_key' => 'AIza-test'],
        http: app(HttpFactory::class),
    );

    catalog()->syncFrom($provider);

    $model = catalog()->find('gemini', 'gemini-2.5-flash');

    expect($model?->model_key)->toBe('gemini-2.5-flash')
        ->and($model?->context_limit)->toBe(1_048_576)
        ->and($model?->max_output_tokens)->toBe(65_536)
        ->and($model?->supports_streaming)->toBeTrue();
});

it('never lets a provider sync clear a price a human entered', function (): void {
    config()->set('pandora.models.catalog', [pricedEntry()]);
    catalog()->seedFromConfig();

    Http::fake(['api.openai.test/*' => Http::response(['data' => [
        ['id' => 'gpt-4o-mini', 'object' => 'model'],
    ]])]);

    catalog()->syncFrom(new OpenAiCompatibleProvider(
        key: 'openai',
        config: ['base_url' => 'https://api.openai.test/v1', 'api_key' => 'sk-test'],
        http: app(HttpFactory::class),
    ));

    // No vendor exposes prices through an API, so a sync that touched this
    // column could only ever be destroying information.
    expect(catalog()->find('openai', 'gpt-4o-mini')?->input_price_per_million)->toEqual(0.15);
});

it('excludes disabled and deprecated models from what is usable', function (): void {
    catalog()->seedFromConfig([
        ['provider' => 'openai', 'key' => 'live'],
        ['provider' => 'openai', 'key' => 'switched-off', 'enabled' => false],
        ['provider' => 'openai', 'key' => 'retired', 'deprecated_at' => now()->subDay()->toDateTimeString()],
        ['provider' => 'openai', 'key' => 'retiring-later', 'deprecated_at' => now()->addYear()->toDateTimeString()],
    ]);

    expect(catalog()->usable()->pluck('model_key')->all())
        ->toBe(['live', 'retiring-later']);
});

it('matches a model against the capabilities a request requires', function (): void {
    catalog()->seedFromConfig([
        ['provider' => 'openai', 'key' => 'text-only', 'capabilities' => ['streaming', 'tools']],
        ['provider' => 'openai', 'key' => 'multimodal', 'capabilities' => ['streaming', 'tools', 'vision']],
    ]);

    $needsVision = new ProviderCapabilities(vision: true);

    expect(catalog()->find('openai', 'text-only')?->satisfies($needsVision))->toBeFalse()
        ->and(catalog()->find('openai', 'multimodal')?->satisfies($needsVision))->toBeTrue()
        // A request that needs nothing special is satisfied by anything.
        ->and(catalog()->find('openai', 'text-only')?->satisfies(new ProviderCapabilities))->toBeTrue();
});
