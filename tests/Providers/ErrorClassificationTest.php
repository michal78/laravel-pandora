<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Pandora\Pandora\Exceptions\Provider\ContextOverflow;
use Pandora\Pandora\Exceptions\Provider\ProviderAuthenticationFailed;
use Pandora\Pandora\Exceptions\Provider\ProviderException;
use Pandora\Pandora\Exceptions\Provider\ProviderQuotaExhausted;
use Pandora\Pandora\Exceptions\Provider\ProviderRateLimited;
use Pandora\Pandora\Exceptions\Provider\ProviderTimeout;
use Pandora\Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Pandora\Providers\Adapters\OpenAiCompatibleProvider;
use Pandora\Pandora\Providers\Data\ChatMessage;
use Pandora\Pandora\Providers\Data\ChatRequest;

/**
 * Classification decides retry and failover, so it is behaviour, not labelling.
 *
 * No test here reaches a network: every response is faked. The quota case comes
 * from a real OpenAI reply observed during the host walkthrough -- a 429 that
 * had been classified as a rate limit and would have been retried three times
 * against an account that had no credit to spend.
 */
function openAiProvider(): OpenAiCompatibleProvider
{
    return new OpenAiCompatibleProvider(
        key: 'openai',
        config: ['base_url' => 'https://api.openai.test/v1', 'api_key' => 'sk-test'],
        http: app(HttpFactory::class),
    );
}

function chatRequest(): ChatRequest
{
    return new ChatRequest(model: 'gpt-4o-mini', messages: [ChatMessage::user('Hello')]);
}

/**
 * @param array<string, mixed>|string $body
 */
function failWith(int $status, array|string $body): ProviderException
{
    Http::fake(['*' => Http::response($body, $status)]);

    try {
        openAiProvider()->chat(chatRequest());
    } catch (ProviderException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the adapter to throw a classified ProviderException.');
}

it('treats an exhausted balance as final, not as a rate limit', function (): void {
    // The exact payload OpenAI returned during the host walkthrough.
    $exception = failWith(429, [
        'error' => [
            'message' => 'You have no credits remaining. Add credits to continue using the API at https://platform.openai.com/settings/organization/billing/.',
            'type' => 'insufficient_quota',
            'code' => 'insufficient_quota',
        ],
    ]);

    expect($exception)->toBeInstanceOf(ProviderQuotaExhausted::class)
        ->and($exception->isRetryable())->toBeFalse()
        // A different provider or key may still have credit.
        ->and($exception->allowsFailover())->toBeTrue();
});

it('still treats a genuine rate limit as retryable', function (): void {
    $exception = failWith(429, [
        'error' => [
            'message' => 'Rate limit reached for gpt-4o-mini. Please try again in 1s.',
            'type' => 'requests',
            'code' => 'rate_limit_exceeded',
        ],
    ]);

    expect($exception)->toBeInstanceOf(ProviderRateLimited::class)
        ->and($exception->isRetryable())->toBeTrue();
});

it('recognises an exhausted quota reported only by code', function (): void {
    // Many OpenAI-compatible servers send the code without readable prose.
    $exception = failWith(429, ['error' => ['code' => 'insufficient_quota']]);

    expect($exception)->toBeInstanceOf(ProviderQuotaExhausted::class);
});

it('classifies the remaining failures behaviourally', function (
    int $status,
    array $body,
    string $expected,
    bool $retryable,
): void {
    $exception = failWith($status, $body);

    expect($exception)->toBeInstanceOf($expected)
        ->and($exception->isRetryable())->toBe($retryable);
})->with([
    'bad key' => [401, ['error' => ['message' => 'Incorrect API key provided.']], ProviderAuthenticationFailed::class, false],
    'gateway timeout' => [504, ['error' => ['message' => 'Gateway timeout.']], ProviderTimeout::class, true],
    'server error' => [500, ['error' => ['message' => 'Internal server error.']], ProviderUnavailable::class, true],
    'context overflow' => [400, ['error' => ['message' => "This model's maximum context length is 128000 tokens."]], ContextOverflow::class, false],
]);

it('never puts the API key in a message a user could see', function (): void {
    $exception = failWith(401, ['error' => ['message' => 'Incorrect API key provided: sk-test.']]);

    expect($exception->userMessage())->not->toContain('sk-test');
});
