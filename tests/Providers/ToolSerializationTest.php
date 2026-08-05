<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Pandora\Pandora\Providers\Adapters\OpenAiCompatibleProvider;
use Pandora\Pandora\Providers\Data\ChatMessage;
use Pandora\Pandora\Providers\Data\ChatRequest;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Providers\Data\ToolDefinition;

/**
 * The request side of tool use.
 *
 * Phase 1 could already read tool calls out of a response; nothing could put
 * tools into a request, or send a result back. A tool loop needs both halves
 * and needs them in the shape the vendor demands.
 */
function toolProvider(): OpenAiCompatibleProvider
{
    return new OpenAiCompatibleProvider(
        key: 'openai',
        config: ['base_url' => 'https://api.openai.test/v1', 'api_key' => 'sk-test'],
        http: app(HttpFactory::class),
    );
}

function fakeCompletion(): void
{
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-1',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'Done.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
    ])]);
}

it('advertises tools in the vendor function shape', function (): void {
    fakeCompletion();

    toolProvider()->chat(new ChatRequest(
        model: 'gpt-4o-mini',
        messages: [ChatMessage::user('Refund it.')],
        tools: [new ToolDefinition(
            name: 'refund_order',
            description: 'Refund an order.',
            schema: ['type' => 'object', 'properties' => ['reference' => ['type' => 'string']]],
        )],
    ));

    Http::assertSent(function ($request): bool {
        expect($request['tools'][0])->toBe([
            'type' => 'function',
            'function' => [
                'name' => 'refund_order',
                'description' => 'Refund an order.',
                'parameters' => ['type' => 'object', 'properties' => ['reference' => ['type' => 'string']]],
            ],
        ]);

        return true;
    });
});

it('omits the tools key entirely when the agent has none', function (): void {
    fakeCompletion();

    toolProvider()->chat(new ChatRequest(model: 'gpt-4o-mini', messages: [ChatMessage::user('Hi')]));

    Http::assertSent(fn ($request): bool => ! array_key_exists('tools', $request->data()));
});

it('serialises an assistant tool request with JSON-encoded arguments', function (): void {
    fakeCompletion();

    toolProvider()->chat(new ChatRequest(
        model: 'gpt-4o-mini',
        messages: [
            ChatMessage::user('Refund order 1234.'),
            ChatMessage::assistantToolCalls('', [
                new ToolCall('call_1', 'refund_order', ['reference' => '1234', 'amount_minor' => 4200]),
            ]),
            ChatMessage::tool('call_1', 'Refund issued.'),
        ],
    ));

    Http::assertSent(function ($request): bool {
        $messages = $request['messages'];

        expect($messages[1]['role'])->toBe('assistant')
            // Present and null: a tool-requesting turn often has no prose, and
            // omitting the key is a 400 from several vendors.
            ->and($messages[1])->toHaveKey('content')
            ->and($messages[1]['content'])->toBeNull()
            ->and($messages[1]['tool_calls'][0]['id'])->toBe('call_1')
            ->and($messages[1]['tool_calls'][0]['type'])->toBe('function')
            ->and($messages[1]['tool_calls'][0]['function']['name'])->toBe('refund_order')
            ->and($messages[1]['tool_calls'][0]['function']['arguments'])
            ->toBe('{"reference":"1234","amount_minor":4200}')
            ->and($messages[2])->toBe([
                'role' => 'tool',
                'content' => 'Refund issued.',
                'tool_call_id' => 'call_1',
            ]);

        return true;
    });
});

it('keeps prose on an assistant turn that also requested a tool', function (): void {
    fakeCompletion();

    toolProvider()->chat(new ChatRequest(
        model: 'gpt-4o-mini',
        messages: [
            ChatMessage::assistantToolCalls('Let me check that.', [
                new ToolCall('call_1', 'lookup_order', ['reference' => '1234']),
            ]),
            ChatMessage::tool('call_1', 'Shipped.'),
        ],
    ));

    Http::assertSent(function ($request): bool {
        expect($request['messages'][0]['content'])->toBe('Let me check that.');

        return true;
    });
});

it('carries tools through a streaming request too', function (): void {
    Http::fake(['*' => Http::response(
        "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\ndata: [DONE]\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);

    toolProvider()->stream(
        new ChatRequest(
            model: 'gpt-4o-mini',
            messages: [ChatMessage::user('Hello')],
            stream: true,
            tools: [new ToolDefinition('lookup_order', 'Look up an order.', ['type' => 'object'])],
        ),
        static function (): void {},
    );

    Http::assertSent(fn ($request): bool => $request['tools'][0]['function']['name'] === 'lookup_order');
});

it('records only tool names on the trace, never their schemas', function (): void {
    $request = new ChatRequest(
        model: 'gpt-4o-mini',
        messages: [ChatMessage::user('Hi')],
        tools: [new ToolDefinition('refund_order', 'Refund an order.', ['type' => 'object'])],
    );

    expect($request->toTrace()['tools'])->toBe(['refund_order']);
});

it('preserves tools across withStreaming and withTools', function (): void {
    $tools = [new ToolDefinition('lookup_order', 'Look up an order.', [])];

    $request = (new ChatRequest(model: 'm', messages: [], tools: $tools))->withStreaming();

    expect($request->tools)->toBe($tools)
        ->and($request->stream)->toBeTrue()
        ->and($request->withTools([])->tools)->toBe([])
        ->and($request->withTools([])->stream)->toBeTrue();
});
