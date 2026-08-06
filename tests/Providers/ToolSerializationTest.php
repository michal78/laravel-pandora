<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Pandora\Pandora\Providers\Adapters\OpenAiCompatibleProvider;
use Pandora\Pandora\Providers\Data\ChatMessage;
use Pandora\Pandora\Providers\Data\ChatRequest;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Providers\Data\ToolDefinition;
use Pandora\Pandora\Tools\ToolRegistry;

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

/**
 * A tool that takes no arguments.
 *
 * Phase 5's walkthrough found this one against the live OpenAI API, and the
 * suite could not have: PHP cannot distinguish an empty map from an empty
 * list, `json_encode` resolves the ambiguity as `[]`, and every assertion in
 * this file until now read `$request['tools']` -- which decodes `{}` back to
 * `[]` and agrees with itself.
 *
 * The failure is also worse than it sounds. A strict provider rejects the
 * whole request, so one parameterless tool disables every other tool in it.
 *
 *     Invalid schema for function 'inspect_run_status':
 *     [] is not of type 'object'.
 *
 * These assertions read the encoded body, because the bug only exists there.
 */
it('encodes an empty properties map as a JSON object, not an empty array', function (): void {
    fakeCompletion();

    toolProvider()->chat(new ChatRequest(
        model: 'gpt-4o-mini',
        messages: [ChatMessage::user('What is happening?')],
        tools: [new ToolDefinition(
            name: 'inspect_run_status',
            description: 'Report on the current run.',
            schema: ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
        )],
    ));

    Http::assertSent(function ($request): bool {
        expect($request->body())->toContain('"properties":{}')
            ->and($request->body())->not->toContain('"properties":[]');

        return true;
    });
});

it('leaves an empty required list as a JSON array', function (): void {
    $definition = new ToolDefinition('t', 'd', [
        'type' => 'object',
        'properties' => [],
        'required' => [],
    ]);

    expect(json_encode($definition->encodableSchema()))
        ->toBe('{"type":"object","properties":{},"required":[]}');
});

it('objectifies a nested empty properties map too', function (): void {
    $definition = new ToolDefinition('t', 'd', [
        'type' => 'object',
        'properties' => [
            'options' => ['type' => 'object', 'properties' => []],
        ],
    ]);

    expect(json_encode($definition->encodableSchema()))
        ->toContain('"options":{"type":"object","properties":{}}');
});

it('objectifies an empty schema inside a list of schemas', function (): void {
    $definition = new ToolDefinition('t', 'd', ['anyOf' => [[], ['type' => 'string']]]);

    expect(json_encode($definition->encodableSchema()))
        ->toBe('{"anyOf":[{},{"type":"string"}]}');
});

it('encodes every built-in tool with an object for properties', function (): void {
    $registry = app(ToolRegistry::class);

    $definitions = $registry->describe($registry->all());

    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $definition) {
        expect(json_encode($definition->encodableSchema()))
            ->not->toContain('"properties":[]', "{$definition->name} advertises properties as an array");
    }
});
