<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Pandora\Exceptions\InvalidConfiguration;
use Pandora\Exceptions\Provider\ProviderRateLimited;
use Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Providers\Adapters\LaravelAi\LaravelAiProvider;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Data\ChatMessage;
use Pandora\Providers\Data\ChatRequest;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\Data\ToolDefinition;
use Pandora\Providers\ProviderManager;
use Pandora\Testing\ProviderContractTests;
use Pandora\Tests\Providers\Contract\AnthropicFixtures;

/**
 * What the shared contract cannot say about the `laravel-ai` adapter: how a
 * connection names its SDK driver, whose credential wins, that a tool schema
 * survives the trip into the SDK's types, and how a failure reported inside a
 * stream is classified.
 */
function laravelAi(array $config = [], bool $withCredentials = false): LaravelAiProvider
{
    return LaravelAiProvider::make(
        key: 'via-sdk',
        config: [
            'adapter' => 'laravel-ai',
            'driver' => 'anthropic',
            'base_url' => 'https://api.anthropic.test/v1',
            'api_key' => 'sk-from-connection',
            ...$config,
        ],
        container: app(),
        credentials: $withCredentials ? app(CredentialManager::class) : null,
    );
}

function laravelAiRequest(array $tools = []): ChatRequest
{
    return new ChatRequest(
        model: 'claude-sonnet-4-5',
        messages: [ChatMessage::user('Where is order 1234?')],
        tools: $tools,
    );
}

function fakeAnthropic(): void
{
    Http::fake(['api.anthropic.test/*' => Http::response((new AnthropicFixtures)->completionResponse('Fine.'))]);
}

it('is what the provider manager builds for the laravel-ai adapter', function (): void {
    $manager = app(ProviderManager::class);
    $manager->addConnection('via-sdk', ['adapter' => 'laravel-ai', 'driver' => 'anthropic']);

    expect($manager->provider('via-sdk'))->toBeInstanceOf(LaravelAiProvider::class);
});

it('refuses a connection that names no SDK driver', function (): void {
    fakeAnthropic();

    expect(fn () => laravelAi(['driver' => null])->chat(laravelAiRequest()))
        ->toThrow(InvalidConfiguration::class, 'names no SDK driver');
});

it('refuses an SDK driver that does not generate text', function (): void {
    expect(fn () => laravelAi(['driver' => 'jina'])->chat(laravelAiRequest()))
        ->toThrow(InvalidConfiguration::class, 'does not generate text');
});

it('reads a provider the host defined in config/ai.php', function (): void {
    config()->set('ai.providers.house-claude', [
        'driver' => 'anthropic',
        'key' => 'sk-from-ai-config',
        'url' => 'https://api.anthropic.test/v1',
    ]);
    fakeAnthropic();

    laravelAi(['driver' => null, 'base_url' => null, 'api_key' => null, 'ai_provider' => 'house-claude'])
        ->chat(laravelAiRequest());

    expect(ProviderContractTests::lastRequest()->header('x-api-key'))->toBe(['sk-from-ai-config']);
});

it('refuses an ai_provider that config/ai.php does not define', function (): void {
    expect(fn () => laravelAi(['ai_provider' => 'nowhere'])->chat(laravelAiRequest()))
        ->toThrow(InvalidConfiguration::class, 'config/ai.php does not define');
});

it('sends a stored Pandora credential over the key in either config file', function (): void {
    // Per-agent and per-tenant keys are the reason the credential is not left
    // to the SDK's own configuration.
    config()->set('ai.providers.house-claude', [
        'driver' => 'anthropic',
        'key' => 'sk-from-ai-config',
        'url' => 'https://api.anthropic.test/v1',
    ]);
    app(CredentialManager::class)->issue('via-sdk', 'sk-from-database');
    fakeAnthropic();

    laravelAi(['ai_provider' => 'house-claude'], withCredentials: true)->chat(laravelAiRequest());

    expect(ProviderContractTests::lastRequest()->header('x-api-key'))->toBe(['sk-from-database']);
});

it('carries a tool schema into the SDK without losing its constraints', function (): void {
    fakeAnthropic();

    laravelAi()->chat(laravelAiRequest([
        new ToolDefinition('lookup_order', 'Look up an order.', [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The order number.'],
                'status' => ['type' => 'string', 'enum' => ['open', 'shipped']],
                'lines' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required' => ['id'],
        ]),
    ]));

    // Decoded from the wire rather than read from the recorded array, where
    // `properties` is still the stdClass the SDK builds it as.
    $schema = json_decode(ProviderContractTests::lastRequest()->body(), true)['tools'][0]['input_schema'];

    expect($schema['required'])->toBe(['id'])
        ->and($schema['properties']['id'])->toMatchArray(['type' => 'string', 'description' => 'The order number.'])
        ->and($schema['properties']['status']['enum'])->toBe(['open', 'shipped'])
        ->and($schema['properties']['lines'])->toMatchArray(['type' => 'array', 'items' => ['type' => 'integer']]);
});

it('never lets the SDK execute a tool', function (): void {
    // The model asks for a tool; the adapter must hand the call back rather
    // than run it. Pandora's loop authorises and records every execution.
    Http::fake(['api.anthropic.test/*' => Http::response((new AnthropicFixtures)->toolCallResponse([
        new ToolCall('toolu_1', 'lookup_order', ['id' => '1234']),
    ]))]);

    $response = laravelAi()->chat(laravelAiRequest([
        new ToolDefinition('lookup_order', 'Look up an order.', ['type' => 'object']),
    ]));

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('lookup_order')
        ->and(Http::recorded())->toHaveCount(1);
});

it('classifies a failure reported inside a stream by its type, not the SDK flag', function (string $type, string $expected): void {
    Http::fake(['api.anthropic.test/*' => Http::response(
        "event: error\ndata: ".json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'Mid-stream.']])."\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);

    expect(fn () => laravelAi()->stream(laravelAiRequest()->withStreaming(), static fn (): null => null))
        ->toThrow($expected);
})->with([
    // Anthropic flags this one unrecoverable. It is the textbook transient.
    'overloaded' => ['overloaded_error', ProviderUnavailable::class],
    'rate limited' => ['rate_limit_error', ProviderRateLimited::class],
]);
