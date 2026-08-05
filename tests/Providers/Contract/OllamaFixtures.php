<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests\Providers\Contract;

use Illuminate\Http\Client\Factory as HttpFactory;
use Pandora\Pandora\Contracts\StreamingProvider;
use Pandora\Pandora\Providers\Adapters\OpenAiCompatibleProvider;

/**
 * Ollama through the OpenAI-compatible adapter.
 *
 * Same dialect, one difference that matters: Ollama reports an error as a
 * bare string rather than an object, so an adapter that assumes
 * `error.message` shows the user "HTTP 400" instead of "model not found".
 */
final class OllamaFixtures extends OpenAiCompatibleFixtures
{
    public function key(): string
    {
        return 'ollama';
    }

    public function model(): string
    {
        return 'llama3.2';
    }

    public function baseUrl(): string
    {
        return 'http://local-inference.test:11434/v1';
    }

    public function endpointPattern(): string
    {
        return 'local-inference.test:11434/*';
    }

    public function apiKey(): string
    {
        // Ollama needs no credential, but the OpenAI client insists on one, so
        // deployments send a placeholder. It must still travel in the header.
        return 'ollama';
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
        return ['error' => $message];
    }
}
