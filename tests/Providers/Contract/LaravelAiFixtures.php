<?php

declare(strict_types=1);

namespace Pandora\Tests\Providers\Contract;

use Pandora\Contracts\StreamingProvider;
use Pandora\Providers\Adapters\LaravelAi\LaravelAiProvider;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\Data\UsageData;
use Pandora\Testing\ProviderFixtures;

/**
 * A vendor's wire dialect, spoken through the Laravel AI SDK.
 *
 * Every method but `make()` delegates to the native adapter's fixtures. That
 * is the whole design of the test: the recorded responses and the readings of
 * what was sent are the SAME ones the native adapter passes against, so the
 * SDK path is held to exactly the same wire behaviour rather than to a second
 * set of fixtures written to agree with it.
 */
final class LaravelAiFixtures implements ProviderFixtures
{
    public function __construct(
        private readonly ProviderFixtures $wire,
        private readonly string $driver,
        private readonly string $baseUrl,
    ) {}

    public function key(): string
    {
        return $this->wire->key();
    }

    public function model(): string
    {
        return $this->wire->model();
    }

    public function endpointPattern(): string
    {
        return $this->wire->endpointPattern();
    }

    public function make(): StreamingProvider
    {
        return LaravelAiProvider::make(
            key: $this->key(),
            config: [
                'adapter' => 'laravel-ai',
                'driver' => $this->driver,
                'base_url' => $this->baseUrl,
                'api_key' => $this->apiKey(),
            ],
            container: app(),
        );
    }

    public function credentialHeader(): string
    {
        return $this->wire->credentialHeader();
    }

    public function apiKey(): string
    {
        return $this->wire->apiKey();
    }

    public function completionResponse(
        string $text,
        int $inputTokens = 11,
        int $outputTokens = 7,
        int $cachedInputTokens = 0,
    ): array {
        return $this->wire->completionResponse($text, $inputTokens, $outputTokens, $cachedInputTokens);
    }

    public function truncatedResponse(string $text): array
    {
        return $this->wire->truncatedResponse($text);
    }

    public function toolCallResponse(array $calls): array
    {
        return $this->wire->toolCallResponse($calls);
    }

    public function streamResponse(string $text, array $toolCalls = [], ?UsageData $usage = null): string
    {
        return $this->wire->streamResponse($text, $toolCalls, $usage);
    }

    public function errorResponse(string $message, ?string $code = null): array
    {
        return $this->wire->errorResponse($message, $code);
    }

    public function contextOverflowMessage(): string
    {
        return $this->wire->contextOverflowMessage();
    }

    public function malformedBody(): string
    {
        return $this->wire->malformedBody();
    }

    public function sentModel(array $body, string $url): ?string
    {
        return $this->wire->sentModel($body, $url);
    }

    public function expectedToolCallId(ToolCall $call, int $index): string
    {
        return $this->wire->expectedToolCallId($call, $index);
    }

    public function correlationFor(ToolCall $call): string
    {
        return $this->wire->correlationFor($call);
    }

    public function sentMessages(array $body): array
    {
        return $this->wire->sentMessages($body);
    }

    public function sentToolNames(array $body): array
    {
        return $this->wire->sentToolNames($body);
    }

    public function sentSystemPrompt(array $body): ?string
    {
        return $this->wire->sentSystemPrompt($body);
    }

    public function sentToolResultCorrelations(array $body): array
    {
        return $this->wire->sentToolResultCorrelations($body);
    }
}
