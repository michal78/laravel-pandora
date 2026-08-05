<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Adapters;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Pandora\Pandora\Contracts\StreamingProvider;
use Pandora\Pandora\Exceptions\InvalidConfiguration;
use Pandora\Pandora\Exceptions\Provider\ProviderTimeout;
use Pandora\Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Pandora\Messages\Enums\MessageRole;
use Pandora\Pandora\Providers\Adapters\Concerns\ClassifiesProviderFailures;
use Pandora\Pandora\Providers\Credentials\CredentialManager;
use Pandora\Pandora\Providers\Data\ChatMessage;
use Pandora\Pandora\Providers\Data\ChatRequest;
use Pandora\Pandora\Providers\Data\ChatResponse;
use Pandora\Pandora\Providers\Data\FinishReason;
use Pandora\Pandora\Providers\Data\ProviderCapabilities;
use Pandora\Pandora\Providers\Data\ProviderHealth;
use Pandora\Pandora\Providers\Data\StreamDelta;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Providers\Data\ToolDefinition;
use Pandora\Pandora\Providers\Data\UsageData;

/**
 * Anthropic's Messages API.
 *
 * Written against Laravel's HTTP client with no vendor SDK, for the same
 * reason the OpenAI adapter is: a `suggest`ed SDK cannot be relied on, and an
 * SDK type that escapes an adapter turns a vendor's minor release into our
 * breaking change.
 *
 * Three things differ from the OpenAI dialect deeply enough to be worth
 * naming, because they are where adapters get written wrong:
 *
 *  1. The system prompt is a TOP-LEVEL field, not a message.
 *  2. Content is a list of typed blocks, and a tool result is a `user` message
 *     carrying a `tool_result` block -- there is no `tool` role.
 *  3. `max_tokens` is required. There is no "as much as you like" default, so
 *     one is configured rather than left to chance.
 */
final class AnthropicProvider implements StreamingProvider
{
    use ClassifiesProviderFailures;

    private const DEFAULT_VERSION = '2023-06-01';

    private const DEFAULT_MAX_TOKENS = 4096;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $key,
        private readonly array $config,
        private readonly HttpFactory $http,
        private readonly ?CredentialManager $credentials = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            streaming: true,
            tools: true,
            // Structured output is expressed through a tool, which is a real
            // capability even though it is not a separate parameter.
            structuredOutput: true,
            vision: true,
            audio: false,
            embeddings: false,
        );
    }

    public function health(): ProviderHealth
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->client()->get('/models');
        } catch (ConnectionException $e) {
            return ProviderHealth::degraded('Connection failed: '.$e->getMessage());
        }

        if ($response->successful()) {
            return ProviderHealth::healthy($this->elapsedMs($startedAt));
        }

        return ProviderHealth::degraded("HTTP {$response->status()}");
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $startedAt = hrtime(true);

        $response = $this->send($this->payload($request, stream: false), $request);

        $body = $response->json();

        // A 200 we cannot parse is a broken response, not an empty answer.
        if (! is_array($body) || ! is_array($body['content'] ?? null)) {
            throw new ProviderUnavailable(
                "Provider [{$this->key}] returned a response that could not be parsed.",
                $this->key,
                $request->model,
            );
        }

        /** @var array<string, mixed> $body */
        return $this->parseResponse($body, $this->elapsedMs($startedAt));
    }

    public function stream(ChatRequest $request, callable $onDelta): ChatResponse
    {
        $startedAt = hrtime(true);

        $content = '';
        /** @var array<int, array{id: string, name: string, arguments: string}> $toolBuffers */
        $toolBuffers = [];
        $finishReason = FinishReason::Stop;
        $inputTokens = 0;
        $outputTokens = 0;
        $cachedInputTokens = 0;
        $responseId = null;

        $response = $this->send($this->payload($request, stream: true), $request, streaming: true);

        foreach ($this->readServerSentEvents($response) as $event) {
            $type = is_string($event['type'] ?? null) ? $event['type'] : '';

            switch ($type) {
                case 'message_start':
                    /** @var array<string, mixed> $message */
                    $message = is_array($event['message'] ?? null) ? $event['message'] : [];
                    $responseId = is_string($message['id'] ?? null) ? $message['id'] : null;

                    if (is_array($message['usage'] ?? null)) {
                        $usage = $this->parseUsage($message['usage']);
                        $inputTokens = $usage->inputTokens;
                        $cachedInputTokens = $usage->cachedInputTokens;
                        $outputTokens = $usage->outputTokens;
                    }

                    break;

                case 'content_block_start':
                    /** @var array<string, mixed> $block */
                    $block = is_array($event['content_block'] ?? null) ? $event['content_block'] : [];

                    if (($block['type'] ?? null) === 'tool_use') {
                        $toolBuffers[(int) ($event['index'] ?? 0)] = [
                            'id' => is_string($block['id'] ?? null) ? $block['id'] : '',
                            'name' => is_string($block['name'] ?? null) ? $block['name'] : '',
                            'arguments' => '',
                        ];
                    }

                    break;

                case 'content_block_delta':
                    /** @var array<string, mixed> $delta */
                    $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];
                    $index = (int) ($event['index'] ?? 0);

                    if (($delta['type'] ?? null) === 'text_delta' && is_string($delta['text'] ?? null)) {
                        $content .= $delta['text'];
                        $onDelta(StreamDelta::text($delta['text']));
                    }

                    if (($delta['type'] ?? null) === 'thinking_delta' && is_string($delta['thinking'] ?? null)) {
                        $onDelta(StreamDelta::reasoning($delta['thinking']));
                    }

                    // Tool arguments arrive as JSON fragments and are only
                    // parseable once the block closes.
                    if (($delta['type'] ?? null) === 'input_json_delta' && is_string($delta['partial_json'] ?? null)) {
                        $toolBuffers[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                        $toolBuffers[$index]['arguments'] .= $delta['partial_json'];
                    }

                    break;

                case 'message_delta':
                    /** @var array<string, mixed> $delta */
                    $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];

                    if (is_string($delta['stop_reason'] ?? null)) {
                        $finishReason = $this->mapFinishReason($delta['stop_reason']);
                    }

                    // Output tokens are only final on this event.
                    if (is_array($event['usage'] ?? null) && isset($event['usage']['output_tokens'])) {
                        $outputTokens = (int) $event['usage']['output_tokens'];
                    }

                    break;
            }
        }

        $toolCalls = $this->assembleToolCalls($toolBuffers);

        foreach ($toolCalls as $call) {
            $onDelta(StreamDelta::toolCall($call));
        }

        $usage = new UsageData(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cachedInputTokens: $cachedInputTokens,
        );

        $onDelta(StreamDelta::usage($usage));
        $onDelta(StreamDelta::done());

        return new ChatResponse(
            content: $content,
            finishReason: $toolCalls === [] ? $finishReason : FinishReason::ToolCalls,
            usage: new UsageData(
                inputTokens: $usage->inputTokens,
                outputTokens: $usage->outputTokens,
                cachedInputTokens: $usage->cachedInputTokens,
                durationMs: $this->elapsedMs($startedAt),
            ),
            toolCalls: $toolCalls,
            providerResponseId: $responseId,
        );
    }

    // ----------------------------------------------------------------- request

    /**
     * @return array<string, mixed>
     */
    private function payload(ChatRequest $request, bool $stream): array
    {
        [$system, $messages] = $this->splitSystemPrompt($request->messages);

        $payload = [
            'model' => $request->model,
            'messages' => $this->conversation($messages),
            // Required by the API. A missing value is a 400, so the default is
            // configuration rather than a guess made at call time.
            'max_tokens' => $request->maxTokens
                ?? (int) ($this->config['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
        ];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        if ($stream) {
            $payload['stream'] = true;
        }

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->tools !== []) {
            $payload['tools'] = array_map(
                static fn (ToolDefinition $tool): array => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'input_schema' => $tool->schema,
                ],
                $request->tools,
            );
        }

        return $payload + $request->options;
    }

    /**
     * Anthropic carries the system prompt at the top level, so it is lifted
     * out of the conversation rather than sent as a message.
     *
     * @param list<ChatMessage> $messages
     * @return array{0: string|null, 1: list<ChatMessage>}
     */
    private function splitSystemPrompt(array $messages): array
    {
        $system = [];
        $rest = [];

        foreach ($messages as $message) {
            if ($message->role === MessageRole::System) {
                $system[] = $message->content;

                continue;
            }

            $rest[] = $message;
        }

        return [$system === [] ? null : implode("\n\n", $system), $rest];
    }

    /**
     * Translate the conversation into content blocks, merging runs of the same
     * role.
     *
     * Merging is not cosmetic. Several tool results answering one assistant
     * turn MUST arrive as several blocks inside a single user message; sent as
     * separate messages the API rejects them.
     *
     * @param list<ChatMessage> $messages
     * @return list<array<string, mixed>>
     */
    private function conversation(array $messages): array
    {
        /** @var list<array{role: string, content: list<array<string, mixed>>}> $out */
        $out = [];

        foreach ($messages as $message) {
            [$role, $blocks] = $this->blocksFor($message);

            if ($blocks === []) {
                continue;
            }

            $last = $out === [] ? null : array_key_last($out);

            if ($last !== null && $out[$last]['role'] === $role) {
                $out[$last]['content'] = [...$out[$last]['content'], ...$blocks];

                continue;
            }

            $out[] = ['role' => $role, 'content' => $blocks];
        }

        return array_map(
            static fn (array $message): array => $message,
            $out,
        );
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function blocksFor(ChatMessage $message): array
    {
        // There is no `tool` role: a result is a block inside a user turn.
        if ($message->role === MessageRole::Tool) {
            return ['user', [[
                'type' => 'tool_result',
                'tool_use_id' => (string) $message->toolCallId,
                'content' => $message->content,
            ]]];
        }

        $blocks = [];

        if ($message->content !== '') {
            $blocks[] = ['type' => 'text', 'text' => $message->content];
        }

        foreach ($message->toolCalls as $call) {
            $blocks[] = [
                'type' => 'tool_use',
                'id' => $call->id,
                'name' => $call->name,
                // An empty argument set must still be an object on the wire,
                // and json_encode turns an empty PHP array into `[]`.
                'input' => $call->arguments === [] ? new \stdClass : $call->arguments,
            ];
        }

        return [
            $message->role === MessageRole::Assistant ? 'assistant' : 'user',
            $blocks,
        ];
    }

    // ---------------------------------------------------------------- response

    /**
     * @param array<string, mixed> $body
     */
    private function parseResponse(array $body, int $durationMs): ChatResponse
    {
        $content = '';
        $reasoning = null;
        $toolCalls = [];

        /** @var array<int, mixed> $blocks */
        $blocks = is_array($body['content'] ?? null) ? $body['content'] : [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            match ($block['type'] ?? null) {
                'text' => $content .= is_string($block['text'] ?? null) ? $block['text'] : '',
                'thinking' => $reasoning = is_string($block['thinking'] ?? null) ? $block['thinking'] : $reasoning,
                'tool_use' => $toolCalls[] = new ToolCall(
                    id: is_string($block['id'] ?? null) ? $block['id'] : uniqid('toolu_'),
                    name: is_string($block['name'] ?? null) ? $block['name'] : '',
                    arguments: is_array($block['input'] ?? null) ? $block['input'] : [],
                ),
                default => null,
            };
        }

        $usage = is_array($body['usage'] ?? null) ? $this->parseUsage($body['usage']) : new UsageData;

        $finishReason = is_string($body['stop_reason'] ?? null)
            ? $this->mapFinishReason($body['stop_reason'])
            : FinishReason::Stop;

        return new ChatResponse(
            content: $content,
            finishReason: $toolCalls === [] ? $finishReason : FinishReason::ToolCalls,
            usage: new UsageData(
                inputTokens: $usage->inputTokens,
                outputTokens: $usage->outputTokens,
                cachedInputTokens: $usage->cachedInputTokens,
                cachedOutputTokens: $usage->cachedOutputTokens,
                durationMs: $durationMs,
            ),
            toolCalls: $toolCalls,
            reasoningSummary: $reasoning,
            providerResponseId: is_string($body['id'] ?? null) ? $body['id'] : null,
        );
    }

    /**
     * Cache reads are input tokens already paid for; cache WRITES are a
     * separate, more expensive class. Keeping them apart is what lets cost
     * estimation be honest about prompt caching.
     *
     * @param array<string, mixed> $usage
     */
    private function parseUsage(array $usage): UsageData
    {
        return new UsageData(
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cachedInputTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cachedOutputTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
        );
    }

    private function mapFinishReason(string $reason): FinishReason
    {
        return match ($reason) {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'max_tokens' => FinishReason::Length,
            'refusal' => FinishReason::ContentFilter,
            default => FinishReason::Stop,
        };
    }

    /**
     * @param array<int, array{id: string, name: string, arguments: string}> $buffers
     * @return list<ToolCall>
     */
    private function assembleToolCalls(array $buffers): array
    {
        ksort($buffers);

        $calls = [];

        foreach ($buffers as $buffer) {
            if ($buffer['name'] === '') {
                continue;
            }

            $decoded = $buffer['arguments'] === ''
                ? []
                : json_decode($buffer['arguments'], true);

            $calls[] = new ToolCall(
                id: $buffer['id'] !== '' ? $buffer['id'] : uniqid('toolu_'),
                name: $buffer['name'],
                // Malformed JSON from a model is a normal occurrence, not an
                // exception: the tool's own validation rejects the call.
                arguments: is_array($decoded) ? $decoded : [],
            );
        }

        return $calls;
    }

    // --------------------------------------------------------------- transport

    /**
     * @param array<string, mixed> $payload
     */
    private function send(array $payload, ChatRequest $request, bool $streaming = false): Response
    {
        try {
            $client = $this->client();

            if ($streaming) {
                $client = $client->withOptions(['stream' => true]);
            }

            $response = $client->post('/messages', $payload);
        } catch (ConnectionException $e) {
            throw new ProviderTimeout(
                "Could not reach provider [{$this->key}]: {$e->getMessage()}",
                $this->key,
                $request->model,
                $e,
            );
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->classifyFailure($response, $request->model);
    }

    protected function providerKey(): string
    {
        return $this->key;
    }

    protected function extractErrorMessage(string $body): ?string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return is_string($error) ? $error : null;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function readServerSentEvents(Response $response): \Generator
    {
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);

                // `event:` lines are ignored: the payload repeats the type,
                // and relying on the header would break the moment a proxy
                // reordered or dropped it.
                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));

                if ($payload === '') {
                    continue;
                }

                $decoded = json_decode($payload, true);

                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        }
    }

    private function client(): PendingRequest
    {
        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw InvalidConfiguration::missingProvider($this->key);
        }

        $headers = [
            'anthropic-version' => is_string($this->config['version'] ?? null)
                ? $this->config['version']
                : self::DEFAULT_VERSION,
        ];

        // Resolved here, at call time -- never on a job payload, never in
        // context, never in a run step.
        $apiKey = $this->credentials?->resolve($this->key)?->secret()
            ?? $this->config['api_key']
            ?? null;

        if (is_string($apiKey) && $apiKey !== '') {
            // Anthropic uses its own header rather than a bearer token.
            $headers['x-api-key'] = $apiKey;
        }

        /** @var list<string> $beta */
        $beta = is_array($this->config['beta'] ?? null) ? $this->config['beta'] : [];

        if ($beta !== []) {
            $headers['anthropic-beta'] = implode(',', $beta);
        }

        return $this->http
            ->baseUrl(rtrim($baseUrl, '/'))
            ->timeout((int) ($this->config['timeout'] ?? 120))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
            ->withHeaders($headers)
            ->acceptJson();
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
