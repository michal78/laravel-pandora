<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Pandora\Pandora\Exceptions\Provider\ProviderQuotaExhausted;
use Pandora\Pandora\Providers\Data\ChatMessage;
use Pandora\Pandora\Providers\Data\ChatRequest;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Testing\ProviderContractTests;
use Pandora\Pandora\Tests\Providers\Contract\AnthropicFixtures;

/**
 * Phase 3 acceptance criteria 1 and 11.
 *
 * The shared suite proves the adapter behaves like every other adapter. The
 * tests below it prove the three things about Anthropic's dialect that the
 * shared suite cannot express, because no other vendor does them.
 */
ProviderContractTests::for(new AnthropicFixtures);

function anthropic(): AnthropicFixtures
{
    return new AnthropicFixtures;
}

function anthropicSent(): array
{
    /** @var array<string, mixed> $body */
    $body = Http::recorded()->last()[0]->data();

    return $body;
}

it('sends the system prompt at the top level and never as a message', function (): void {
    ProviderContractTests::fake(anthropic(), anthropic()->completionResponse('Fine.'));

    anthropic()->make()->chat(new ChatRequest(
        model: anthropic()->model(),
        messages: [
            ChatMessage::system('You are terse.'),
            ChatMessage::system('You never speculate.'),
            ChatMessage::user('Hello'),
        ],
    ));

    $body = anthropicSent();

    expect($body['system'])->toBe("You are terse.\n\nYou never speculate.")
        ->and(array_column($body['messages'], 'role'))->toBe(['user']);
});

it('always sends max_tokens, because the API requires it', function (): void {
    ProviderContractTests::fake(anthropic(), anthropic()->completionResponse('Fine.'));

    anthropic()->make()->chat(new ChatRequest(
        model: anthropic()->model(),
        messages: [ChatMessage::user('Hello')],
    ));

    expect(anthropicSent()['max_tokens'])->toBe(4096);
});

it('honours an explicit max_tokens over the configured default', function (): void {
    ProviderContractTests::fake(anthropic(), anthropic()->completionResponse('Fine.'));

    anthropic()->make()->chat(new ChatRequest(
        model: anthropic()->model(),
        messages: [ChatMessage::user('Hello')],
        maxTokens: 256,
    ));

    expect(anthropicSent()['max_tokens'])->toBe(256);
});

it('carries a tool result inside a user turn, because there is no tool role', function (): void {
    ProviderContractTests::fake(anthropic(), anthropic()->completionResponse('It shipped.'));

    anthropic()->make()->chat(new ChatRequest(
        model: anthropic()->model(),
        messages: [
            ChatMessage::user('Where is order 1234?'),
            ChatMessage::assistantToolCalls('', [new ToolCall('toolu_1', 'lookup_order', ['id' => '1234'])]),
            ChatMessage::tool('toolu_1', '{"status":"shipped"}', 'lookup_order'),
        ],
    ));

    $messages = anthropicSent()['messages'];

    expect(array_column($messages, 'role'))->toBe(['user', 'assistant', 'user'])
        ->and($messages[1]['content'][0]['type'])->toBe('tool_use')
        ->and($messages[2]['content'][0])->toBe([
            'type' => 'tool_result',
            'tool_use_id' => 'toolu_1',
            'content' => '{"status":"shipped"}',
        ]);
});

it('merges several tool results into one user turn, as the API demands', function (): void {
    ProviderContractTests::fake(anthropic(), anthropic()->completionResponse('Both done.'));

    anthropic()->make()->chat(new ChatRequest(
        model: anthropic()->model(),
        messages: [
            ChatMessage::user('Check both orders.'),
            ChatMessage::assistantToolCalls('', [
                new ToolCall('toolu_1', 'lookup_order', ['id' => '1']),
                new ToolCall('toolu_2', 'lookup_order', ['id' => '2']),
            ]),
            ChatMessage::tool('toolu_1', '{"status":"shipped"}'),
            ChatMessage::tool('toolu_2', '{"status":"pending"}'),
        ],
    ));

    $messages = anthropicSent()['messages'];

    // Three messages, not four: the two results belong to one turn. Sent
    // separately, the API rejects them.
    expect($messages)->toHaveCount(3)
        ->and($messages[2]['content'])->toHaveCount(2)
        ->and(array_column($messages[2]['content'], 'tool_use_id'))->toBe(['toolu_1', 'toolu_2']);
});

it('separates cache reads from cache writes in usage', function (): void {
    $body = anthropic()->completionResponse('Cached.');
    $body['usage'] = [
        'input_tokens' => 40,
        'output_tokens' => 12,
        'cache_read_input_tokens' => 1_800,
        'cache_creation_input_tokens' => 300,
    ];

    ProviderContractTests::fake(anthropic(), $body);

    $usage = anthropic()->make()->chat(new ChatRequest(
        model: anthropic()->model(),
        messages: [ChatMessage::user('Hello')],
    ))->usage;

    // Kept apart because they are priced differently; collapsing them would
    // make every cached run's cost estimate wrong.
    expect($usage->cachedInputTokens)->toBe(1_800)
        ->and($usage->cachedOutputTokens)->toBe(300)
        ->and($usage->inputTokens)->toBe(40);
});

it('treats an exhausted balance as final even though Anthropic reports it as a 400', function (): void {
    $exception = ProviderContractTests::failWith(
        anthropic(),
        400,
        anthropic()->errorResponse('Your credit balance is too low to access the Claude API.'),
    );

    expect($exception)->toBeInstanceOf(ProviderQuotaExhausted::class)
        ->and($exception->isRetryable())->toBeFalse();
});

it('sends the configured API version header', function (): void {
    ProviderContractTests::fake(anthropic(), anthropic()->completionResponse('Fine.'));

    anthropic()->make()->chat(new ChatRequest(
        model: anthropic()->model(),
        messages: [ChatMessage::user('Hello')],
    ));

    expect(Http::recorded()->last()[0]->header('anthropic-version'))->toBe(['2023-06-01']);
});
