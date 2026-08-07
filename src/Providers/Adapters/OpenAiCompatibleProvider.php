<?php

declare(strict_types=1);

namespace Pandora\Providers\Adapters;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Pandora\Contracts\ModelCatalogProvider;
use Pandora\Contracts\StreamingProvider;
use Pandora\Exceptions\InvalidConfiguration;
use Pandora\Exceptions\Provider\ProviderTimeout;
use Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Providers\Adapters\Concerns\ClassifiesProviderFailures;
use Pandora\Providers\Catalog\ModelDescriptor;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Data\ChatMessage;
use Pandora\Providers\Data\ChatRequest;
use Pandora\Providers\Data\ChatResponse;
use Pandora\Providers\Data\FinishReason;
use Pandora\Providers\Data\ProviderCapabilities;
use Pandora\Providers\Data\ProviderHealth;
use Pandora\Providers\Data\StreamDelta;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\Data\ToolDefinition;
use Pandora\Providers\Data\UsageData;

/**
 * The workhorse adapter: anything speaking the OpenAI chat-completions shape.
 *
 * Covers OpenAI, Ollama, OpenRouter, vLLM, llama.cpp, LM Studio and most
 * self-hosted inference servers. Written against Laravel's HTTP client with no
 * vendor SDK, which keeps the dependency footprint at zero.
 *
 * Every failure is classified into the Pandora exception hierarchy -- the
 * execution loop routes on `isRetryable()` and `allowsFailover()`, so an
 * unclassified error would either retry forever or fail a run that a fallback
 * model could have completed.
 */
final class OpenAiCompatibleProvider implements ModelCatalogProvider, StreamingProvider
{
    use ClassifiesProviderFailures;

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
            structuredOutput: true,
            vision: (bool) ($this->config['supports_vision'] ?? false),
            audio: false,
            embeddings: true,
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

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        if ($response->successful()) {
            return ProviderHealth::healthy($latencyMs);
        }

        return ProviderHealth::degraded("HTTP {$response->status()}");
    }

    /**
     * The `/models` list. Ids only: the endpoint reports neither context
     * limits nor prices, and inventing either would be worse than leaving
     * them for configuration to state.
     *
     * @return list<ModelDescriptor>
     */
    public function models(): array
    {
        $response = $this->get('/models');

        /** @var array<int, mixed> $data */
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        $models = [];

        foreach ($data as $entry) {
            $id = is_array($entry) && is_string($entry['id'] ?? null) ? $entry['id'] : null;

            if ($id === null) {
                continue;
            }

            $models[] = new ModelDescriptor(providerKey: $this->key, modelKey: $id);
        }

        return $models;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $startedAt = hrtime(true);

        $response = $this->send($this->payload($request, stream: false), $request);

        $body = $response->json();

        // A 200 whose body will not parse is a broken response, not a bad
        // request: a truncated transfer, a proxy that mangled the stream, a
        // server that died mid-write. Treated as unavailability, so it is
        // retried and can fail over, rather than as a PHP error thrown from
        // somewhere deep in parsing.
        if (! is_array($body) || ! is_array($body['choices'] ?? null)) {
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
        /** @var array<int, array{id: string, name: string, arguments: string}> $toolCallBuffers */
        $toolCallBuffers = [];
        $finishReason = FinishReason::Stop;
        $usage = null;
        $responseId = null;

        $response = $this->send($this->payload($request, stream: true), $request, streaming: true);

        foreach ($this->readServerSentEvents($response) as $event) {
            $responseId ??= isset($event['id']) && is_string($event['id']) ? $event['id'] : null;

            if (isset($event['usage']) && is_array($event['usage'])) {
                $usage = $this->parseUsage($event['usage']);
            }

            /** @var array<string, mixed>|null $choice */
            $choice = $event['choices'][0] ?? null;

            if ($choice === null) {
                continue;
            }

            if (isset($choice['finish_reason']) && is_string($choice['finish_reason'])) {
                $finishReason = $this->mapFinishReason($choice['finish_reason']);
            }

            /** @var array<string, mixed> $delta */
            $delta = $choice['delta'] ?? [];

            if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                $content .= $delta['content'];
                $onDelta(StreamDelta::text($delta['content']));
            }

            if (isset($delta['reasoning_content']) && is_string($delta['reasoning_content'])) {
                $onDelta(StreamDelta::reasoning($delta['reasoning_content']));
            }

            // Tool-call arguments arrive as JSON fragments across many events
            // and must be reassembled by index before they can be parsed.
            if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $fragment) {
                    if (! is_array($fragment)) {
                        continue;
                    }

                    $index = (int) ($fragment['index'] ?? 0);

                    $toolCallBuffers[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];

                    if (isset($fragment['id']) && is_string($fragment['id'])) {
                        $toolCallBuffers[$index]['id'] = $fragment['id'];
                    }

                    /** @var array<string, mixed> $function */
                    $function = $fragment['function'] ?? [];

                    if (isset($function['name']) && is_string($function['name'])) {
                        $toolCallBuffers[$index]['name'] = $function['name'];
                    }

                    if (isset($function['arguments']) && is_string($function['arguments'])) {
                        $toolCallBuffers[$index]['arguments'] .= $function['arguments'];
                    }
                }
            }
        }

        $toolCalls = $this->assembleToolCalls($toolCallBuffers);

        foreach ($toolCalls as $call) {
            $onDelta(StreamDelta::toolCall($call));
        }

        $usage ??= new UsageData(
            inputTokens: 0,
            outputTokens: (int) ceil(mb_strlen($content) / 4),
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
                reasoningTokens: $usage->reasoningTokens,
                durationMs: $this->elapsedMs($startedAt),
            ),
            toolCalls: $toolCalls,
            providerResponseId: $responseId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ChatRequest $request, bool $stream): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => array_map(
                fn (ChatMessage $m): array => $this->message($m),
                $request->messages,
            ),
            'stream' => $stream,
        ];

        if ($request->tools !== []) {
            $payload['tools'] = array_map(
                static fn (ToolDefinition $t): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $t->name,
                        'description' => $t->description,
                        'parameters' => $t->encodableSchema(),
                    ],
                ],
                $request->tools,
            );
        }

        if ($stream) {
            // Ask for usage on the final chunk; servers that do not understand
            // this option ignore it, and we fall back to an estimate.
            $payload['stream_options'] = ['include_usage' => true];
        }

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        return $payload + $request->options;
    }

    /**
     * One message in the vendor's shape.
     *
     * Tool-call serialisation lives here rather than on the DTO: the wire
     * format is OpenAI's, and a DTO that knew it would stop being neutral.
     *
     * @return array<string, mixed>
     */
    private function message(ChatMessage $message): array
    {
        $payload = array_filter([
            'role' => $message->role->value,
            'content' => $message->content,
            'tool_call_id' => $message->toolCallId,
            'name' => $message->name,
        ], static fn (mixed $value): bool => $value !== null);

        if ($message->toolCalls !== []) {
            // An assistant turn that requested tools may legitimately carry no
            // text; the key must still be present and null.
            $payload['content'] = $message->content === '' ? null : $message->content;
            $payload['tool_calls'] = array_map(
                static fn (ToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->name,
                        'arguments' => json_encode($call->arguments, JSON_THROW_ON_ERROR),
                    ],
                ],
                $message->toolCalls,
            );
        }

        return $payload;
    }

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

            $response = $client->post('/chat/completions', $payload);
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
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return $error['message'];
        }

        return is_string($error) ? $error : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function parseResponse(array $body, int $durationMs): ChatResponse
    {
        /** @var array<string, mixed> $choice */
        $choice = $body['choices'][0] ?? [];
        /** @var array<string, mixed> $message */
        $message = $choice['message'] ?? [];

        $content = isset($message['content']) && is_string($message['content'])
            ? $message['content']
            : '';

        $toolCalls = [];

        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                /** @var array<string, mixed> $function */
                $function = $raw['function'] ?? [];

                $toolCalls[] = new ToolCall(
                    id: is_string($raw['id'] ?? null) ? $raw['id'] : uniqid('call_'),
                    name: is_string($function['name'] ?? null) ? $function['name'] : '',
                    arguments: $this->decodeArguments(
                        is_string($function['arguments'] ?? null) ? $function['arguments'] : '{}',
                    ),
                );
            }
        }

        $usage = isset($body['usage']) && is_array($body['usage'])
            ? $this->parseUsage($body['usage'])
            : new UsageData;

        $finishReason = isset($choice['finish_reason']) && is_string($choice['finish_reason'])
            ? $this->mapFinishReason($choice['finish_reason'])
            : FinishReason::Stop;

        return new ChatResponse(
            content: $content,
            finishReason: $toolCalls === [] ? $finishReason : FinishReason::ToolCalls,
            usage: new UsageData(
                inputTokens: $usage->inputTokens,
                outputTokens: $usage->outputTokens,
                cachedInputTokens: $usage->cachedInputTokens,
                reasoningTokens: $usage->reasoningTokens,
                durationMs: $durationMs,
            ),
            toolCalls: $toolCalls,
            reasoningSummary: is_string($message['reasoning_content'] ?? null)
                ? $message['reasoning_content']
                : null,
            providerResponseId: is_string($body['id'] ?? null) ? $body['id'] : null,
        );
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function parseUsage(array $usage): UsageData
    {
        /** @var array<string, mixed> $promptDetails */
        $promptDetails = $usage['prompt_tokens_details'] ?? [];
        /** @var array<string, mixed> $completionDetails */
        $completionDetails = $usage['completion_tokens_details'] ?? [];

        return new UsageData(
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            cachedInputTokens: (int) ($promptDetails['cached_tokens'] ?? 0),
            reasoningTokens: (int) ($completionDetails['reasoning_tokens'] ?? 0),
        );
    }

    private function mapFinishReason(string $reason): FinishReason
    {
        return match ($reason) {
            'stop', 'end_turn' => FinishReason::Stop,
            'tool_calls', 'function_call' => FinishReason::ToolCalls,
            'length', 'max_tokens' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
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

            $calls[] = new ToolCall(
                id: $buffer['id'] !== '' ? $buffer['id'] : uniqid('call_'),
                name: $buffer['name'],
                arguments: $this->decodeArguments($buffer['arguments']),
            );
        }

        return $calls;
    }

    /**
     * Tool arguments are untrusted model output: malformed JSON is a normal
     * occurrence, not an exception. The caller sees empty arguments and the
     * tool's own validation rejects the call.
     *
     * @return array<string, mixed>
     */
    private function decodeArguments(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
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

            while (($newlinePosition = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePosition));
                $buffer = substr($buffer, $newlinePosition + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));

                if ($payload === '' || $payload === '[DONE]') {
                    continue;
                }

                $decoded = json_decode($payload, true);

                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        }
    }

    /**
     * A plain GET against this provider, classified like any other call.
     *
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        try {
            $response = $this->client()->get($path);
        } catch (ConnectionException $e) {
            throw new ProviderTimeout(
                "Could not reach provider [{$this->key}]: {$e->getMessage()}",
                $this->key,
                null,
                $e,
            );
        }

        if (! $response->successful()) {
            throw $this->classifyFailure($response, null);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderUnavailable(
                "Provider [{$this->key}] returned a response that could not be parsed.",
                $this->key,
            );
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    private function client(): PendingRequest
    {
        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw InvalidConfiguration::missingProvider($this->key);
        }

        $request = $this->http
            ->baseUrl(rtrim($baseUrl, '/'))
            ->timeout((int) ($this->config['timeout'] ?? 120))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
            ->acceptJson();

        // Resolved here, at call time -- never held on a job payload, never
        // placed in context, never written to a run step. The manager walks
        // agent -> tenant -> deployment -> config -> environment; the raw
        // config value is the fallback for a manager-less construction.
        $credential = $this->credentials?->resolve($this->key);

        $apiKey = $credential?->secret() ?? $this->config['api_key'] ?? null;

        if (is_string($apiKey) && $apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $organization = $this->config['organization'] ?? null;

        if (is_string($organization) && $organization !== '') {
            $request = $request->withHeaders(['OpenAI-Organization' => $organization]);
        }

        return $request;
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
