<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Pandora\Pandora\Messages\Enums\MessageRole;
use Pandora\Pandora\Providers\Data\ChatMessage;
use Pandora\Pandora\Providers\Data\ChatRequest;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Testing\ProviderContractTests;
use Pandora\Pandora\Tests\Providers\Contract\GeminiFixtures;

/**
 * Phase 3 acceptance criteria 1 and 12.
 */
ProviderContractTests::for(new GeminiFixtures);

function gemini(): GeminiFixtures
{
    return new GeminiFixtures;
}

function geminiSent(): array
{
    /** @var array<string, mixed> $body */
    $body = Http::recorded()->last()[0]->data();

    return $body;
}

it('names the model in the path rather than the body', function (): void {
    ProviderContractTests::fake(gemini(), gemini()->completionResponse('Fine.'));

    gemini()->make()->chat(new ChatRequest(
        model: 'gemini-2.5-pro',
        messages: [ChatMessage::user('Hello')],
    ));

    expect(Http::recorded()->last()[0]->url())
        ->toContain('/models/gemini-2.5-pro:generateContent')
        ->and(geminiSent())->not->toHaveKey('model');
});

it('calls the streaming endpoint with server-sent events', function (): void {
    ProviderContractTests::fakeStream(gemini(), gemini()->streamResponse('Hello there.'));

    gemini()->make()->stream(
        new ChatRequest(model: gemini()->model(), messages: [ChatMessage::user('Hi')], stream: true),
        static fn () => null,
    );

    expect(Http::recorded()->last()[0]->url())
        ->toContain(':streamGenerateContent')
        ->toContain('alt=sse');
});

it('calls the assistant role "model"', function (): void {
    ProviderContractTests::fake(gemini(), gemini()->completionResponse('Fine.'));

    gemini()->make()->chat(new ChatRequest(
        model: gemini()->model(),
        messages: [ChatMessage::user('Hi'), ChatMessage::assistant('Hello'), ChatMessage::user('Bye')],
    ));

    expect(array_column(geminiSent()['contents'], 'role'))->toBe(['user', 'model', 'user']);
});

it('gives two parallel calls to the same function distinguishable ids', function (): void {
    ProviderContractTests::fake(gemini(), gemini()->toolCallResponse([
        new ToolCall('ignored', 'lookup_order', ['id' => '1']),
        new ToolCall('ignored', 'lookup_order', ['id' => '2']),
    ]));

    $response = gemini()->make()->chat(new ChatRequest(
        model: gemini()->model(),
        messages: [ChatMessage::user('Check both.')],
    ));

    // Gemini correlates by function name alone, so two calls to one function
    // would otherwise be indistinguishable and could not be answered
    // separately. The synthesised id is what makes the rest of Pandora able
    // to treat Gemini like every other provider.
    expect(array_map(static fn (ToolCall $c): string => $c->id, $response->toolCalls))
        ->toBe(['lookup_order#0', 'lookup_order#1'])
        ->and($response->toolCalls[1]->arguments)->toBe(['id' => '2']);
});

it('resolves a synthesised id back to a function name when replaying a result', function (): void {
    ProviderContractTests::fake(gemini(), gemini()->completionResponse('Both done.'));

    gemini()->make()->chat(new ChatRequest(
        model: gemini()->model(),
        messages: [
            ChatMessage::user('Check both.'),
            ChatMessage::assistantToolCalls('', [
                new ToolCall('lookup_order#0', 'lookup_order', ['id' => '1']),
                new ToolCall('lookup_order#1', 'lookup_order', ['id' => '2']),
            ]),
            new ChatMessage(
                role: MessageRole::Tool,
                content: '{"status":"shipped"}',
                toolCallId: 'lookup_order#0',
            ),
        ],
    ));

    $parts = geminiSent()['contents'][2]['parts'];

    expect($parts[0]['functionResponse']['name'])->toBe('lookup_order')
        ->and($parts[0]['functionResponse']['response'])->toBe(['status' => 'shipped']);
});

it('wraps a non-JSON tool result, because a functionResponse must be an object', function (): void {
    ProviderContractTests::fake(gemini(), gemini()->completionResponse('Noted.'));

    gemini()->make()->chat(new ChatRequest(
        model: gemini()->model(),
        messages: [
            ChatMessage::user('Check.'),
            ChatMessage::assistantToolCalls('', [new ToolCall('lookup_order#0', 'lookup_order')]),
            ChatMessage::tool('lookup_order#0', 'shipped on Tuesday', 'lookup_order'),
        ],
    ));

    expect(geminiSent()['contents'][2]['parts'][0]['functionResponse']['response'])
        ->toBe(['result' => 'shipped on Tuesday']);
});

it('reports reasoning separately from the answer', function (): void {
    $body = gemini()->completionResponse('The answer is 42.');
    $body['candidates'][0]['content']['parts'] = [
        ['text' => 'Let me think about this.', 'thought' => true],
        ['text' => 'The answer is 42.'],
    ];
    $body['usageMetadata']['thoughtsTokenCount'] = 96;

    ProviderContractTests::fake(gemini(), $body);

    $response = gemini()->make()->chat(new ChatRequest(
        model: gemini()->model(),
        messages: [ChatMessage::user('What is the answer?')],
    ));

    // A thought concatenated into the answer would be shown to the user as
    // though the model had said it.
    expect($response->content)->toBe('The answer is 42.')
        ->and($response->reasoningSummary)->toBe('Let me think about this.')
        ->and($response->usage->reasoningTokens)->toBe(96);
});

it('never puts the credential in the query string', function (): void {
    ProviderContractTests::fake(gemini(), gemini()->completionResponse('Fine.'));

    gemini()->make()->chat(new ChatRequest(
        model: gemini()->model(),
        messages: [ChatMessage::user('Hello')],
    ));

    // Google's own examples use ?key=, which puts the credential in every
    // proxy log, browser history and error report along the way.
    expect(Http::recorded()->last()[0]->url())->not->toContain('key=')
        ->and(Http::recorded()->last()[0]->header('x-goog-api-key'))->toBe([gemini()->apiKey()]);
});
