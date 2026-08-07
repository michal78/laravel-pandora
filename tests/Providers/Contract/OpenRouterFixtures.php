<?php

declare(strict_types=1);

namespace Pandora\Tests\Providers\Contract;

use Illuminate\Http\Client\Factory as HttpFactory;
use Pandora\Contracts\StreamingProvider;
use Pandora\Providers\Adapters\OpenAiCompatibleProvider;

/**
 * OpenRouter through the OpenAI-compatible adapter.
 *
 * The dialect is OpenAI's, but the error body is its own: a numeric `code`
 * and a `metadata` bag naming the upstream provider that actually failed.
 */
final class OpenRouterFixtures extends OpenAiCompatibleFixtures
{
    public function key(): string
    {
        return 'openrouter';
    }

    public function model(): string
    {
        return 'anthropic/claude-sonnet-4.5';
    }

    public function baseUrl(): string
    {
        return 'https://openrouter.test/api/v1';
    }

    public function endpointPattern(): string
    {
        return 'openrouter.test/*';
    }

    public function apiKey(): string
    {
        return 'sk-or-v1-contract-suite-key';
    }

    public function make(): StreamingProvider
    {
        return new OpenAiCompatibleProvider(
            key: $this->key(),
            config: ['base_url' => $this->baseUrl(), 'api_key' => $this->apiKey()],
            http: app(HttpFactory::class),
        );
    }

    public function errorResponse(string $message, ?string $code = null): array
    {
        return ['error' => [
            'code' => 400,
            'message' => $message,
            'metadata' => ['provider_name' => 'Anthropic'],
        ]];
    }
}
