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
use Pandora\Messages\Enums\MessageRole;
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
 * Google's Gemini API.
 *
 * The dialect differs from both of the others in ways that matter:
 *
 *  1. The model is named in the PATH, not the body.
 *  2. The assistant role is called `model`.
 *  3. **Function calls carry no id.** Every other vendor gives each tool call
 *     an identifier that its result quotes back; Gemini correlates by function
 *     NAME alone. Two parallel calls to the same function would therefore be
 *     indistinguishable, so this adapter synthesises `name#index` ids and
 *     resolves them back to a name on the way out. Nothing above the adapter
 *     has to know that Gemini is different.
 *
 * The credential goes in `x-goog-api-key`, never the query string, so it
 * cannot end up in a proxy log or a browser history.
 */
final class GeminiProvider implements ModelCatalogProvider, StreamingProvider
{
    use ClassifiesProviderFailures;

    /** Separates a synthesised id from its ordinal. Illegal in a function name. */
    private const ID_SEPARATOR = '#';

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
            vision: true,
            audio: true,
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

        if ($response->successful()) {
            return ProviderHealth::healthy($this->elapsedMs($startedAt));
        }

        return ProviderHealth::degraded("HTTP {$response->status()}");
    }

    /**
     * The `/models` list.
     *
     * Alone among the three, Gemini reports genuine per-model facts -- token
     * limits and which generation methods a model supports -- so the sync has
     * something real to record rather than an id and a shrug.
     *
     * @return list<ModelDescriptor>
     */
    public function models(): array
    {
        $response = $this->get('/models');

        /** @var array<int, mixed> $entries */
        $entries = is_array($response['models'] ?? null) ? $response['models'] : [];

        $models = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
                continue;
            }

            /** @var list<string> $methods */
            $methods = is_array($entry['supportedGenerationMethods'] ?? null)
                ? $entry['supportedGenerationMethods']
                : [];

            $models[] = new ModelDescriptor(
                providerKey: $this->key,
                // The API returns `models/gemini-2.5-flash`; everything else
                // in Pandora names the model without the prefix.
                modelKey: (string) preg_replace('#^models/#', '', $entry['name']),
                displayName: is_string($entry['displayName'] ?? null) ? $entry['displayName'] : null,
                contextLimit: isset($entry['inputTokenLimit']) ? (int) $entry['inputTokenLimit'] : null,
                maxOutputTokens: isset($entry['outputTokenLimit']) ? (int) $entry['outputTokenLimit'] : null,
                capabilities: new ProviderCapabilities(
                    streaming: in_array('streamGenerateContent', $methods, true),
                    tools: in_array('generateContent', $methods, true),
                    structuredOutput: in_array('generateContent', $methods, true),
                    vision: in_array('generateContent', $methods, true),
                    embeddings: in_array('embedContent', $methods, true),
                ),
            );
        }

        return $models;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $startedAt = hrtime(true);

        $response = $this->send($request, stream: false);

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['candidates'] ?? null)) {
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
        $toolCalls = [];
        $finishReason = FinishReason::Stop;
        $usage = new UsageData;
        $responseId = null;

        $response = $this->send($request, stream: true);

        foreach ($this->readServerSentEvents($response) as $event) {
            $responseId ??= is_string($event['responseId'] ?? null) ? $event['responseId'] : null;

            if (is_array($event['usageMetadata'] ?? null)) {
                // Gemini repeats cumulative usage on every chunk, so the last
                // one seen is the true total rather than a running sum.
                $usage = $this->parseUsage($event['usageMetadata']);
            }

            /** @var array<string, mixed>|null $candidate */
            $candidate = is_array($event['candidates'][0] ?? null) ? $event['candidates'][0] : null;

            if ($candidate === null) {
                continue;
            }

            if (is_string($candidate['finishReason'] ?? null)) {
                $finishReason = $this->mapFinishReason($candidate['finishReason']);
            }

            /** @var array<int, mixed> $parts */
            $parts = is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [];

            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (is_string($part['text'] ?? null) && $part['text'] !== '') {
                    // Gemini marks its own reasoning with `thought`, which
                    // must not be concatenated into the answer.
                    if (($part['thought'] ?? false) === true) {
                        $onDelta(StreamDelta::reasoning($part['text']));

                        continue;
                    }

                    $content .= $part['text'];
                    $onDelta(StreamDelta::text($part['text']));
                }

                if (is_array($part['functionCall'] ?? null)) {
                    // Whole calls arrive at once -- there is no fragment
                    // reassembly to do, which is the one place this API is
                    // simpler than the others.
                    $toolCalls[] = $this->toolCallFrom($part['functionCall'], count($toolCalls));
                }
            }
        }

        foreach ($toolCalls as $call) {
            $onDelta(StreamDelta::toolCall($call));
        }

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

    // ----------------------------------------------------------------- request

    /**
     * @return array<string, mixed>
     */
    private function payload(ChatRequest $request): array
    {
        [$system, $messages] = $this->splitSystemPrompt($request->messages);

        $payload = ['contents' => $this->contents($messages)];

        if ($system !== null) {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $generationConfig = [];

        if ($request->maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $request->maxTokens;
        }

        if ($request->temperature !== null) {
            $generationConfig['temperature'] = $request->temperature;
        }

        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        if ($request->tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(
                    static fn (ToolDefinition $tool): array => [
                        'name' => $tool->name,
                        'description' => $tool->description,
                        'parameters' => $tool->encodableSchema(),
                    ],
                    $request->tools,
                ),
            ]];
        }

        return $payload + $request->options;
    }

    /**
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
     * @param list<ChatMessage> $messages
     * @return list<array<string, mixed>>
     */
    private function contents(array $messages): array
    {
        /** @var list<array{role: string, parts: list<array<string, mixed>>}> $out */
        $out = [];

        foreach ($messages as $message) {
            [$role, $parts] = $this->partsFor($message);

            if ($parts === []) {
                continue;
            }

            $last = $out === [] ? null : array_key_last($out);

            // Runs of the same role are merged, so several tool results
            // answering one turn arrive together as the API expects.
            if ($last !== null && $out[$last]['role'] === $role) {
                $out[$last]['parts'] = [...$out[$last]['parts'], ...$parts];

                continue;
            }

            $out[] = ['role' => $role, 'parts' => $parts];
        }

        return array_map(static fn (array $content): array => $content, $out);
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function partsFor(ChatMessage $message): array
    {
        if ($message->role === MessageRole::Tool) {
            return ['user', [[
                'functionResponse' => [
                    // The id was ours, not Gemini's: it goes back to being a
                    // name on the wire.
                    'name' => $message->name ?? $this->nameFromId((string) $message->toolCallId),
                    'response' => $this->responseObject($message->content),
                ],
            ]]];
        }

        $parts = [];

        if ($message->content !== '') {
            $parts[] = ['text' => $message->content];
        }

        foreach ($message->toolCalls as $call) {
            $parts[] = ['functionCall' => [
                'name' => $call->name,
                'args' => $call->arguments === [] ? new \stdClass : $call->arguments,
            ]];
        }

        return [
            $message->role === MessageRole::Assistant ? 'model' : 'user',
            $parts,
        ];
    }

    /**
     * A functionResponse must be an OBJECT. A tool that returned a bare string
     * is wrapped rather than rejected.
     *
     * @return array<string, mixed>
     */
    private function responseObject(string $content): array
    {
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : ['result' => $content];
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

        /** @var array<string, mixed> $candidate */
        $candidate = is_array($body['candidates'][0] ?? null) ? $body['candidates'][0] : [];

        /** @var array<int, mixed> $parts */
        $parts = is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [];

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (is_string($part['text'] ?? null)) {
                if (($part['thought'] ?? false) === true) {
                    $reasoning = ($reasoning ?? '').$part['text'];
                } else {
                    $content .= $part['text'];
                }
            }

            if (is_array($part['functionCall'] ?? null)) {
                $toolCalls[] = $this->toolCallFrom($part['functionCall'], count($toolCalls));
            }
        }

        $usage = is_array($body['usageMetadata'] ?? null)
            ? $this->parseUsage($body['usageMetadata'])
            : new UsageData;

        $finishReason = is_string($candidate['finishReason'] ?? null)
            ? $this->mapFinishReason($candidate['finishReason'])
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
            reasoningSummary: $reasoning,
            providerResponseId: is_string($body['responseId'] ?? null) ? $body['responseId'] : null,
        );
    }

    /**
     * @param array<string, mixed> $call
     */
    private function toolCallFrom(array $call, int $index): ToolCall
    {
        $name = is_string($call['name'] ?? null) ? $call['name'] : '';

        return new ToolCall(
            id: $name.self::ID_SEPARATOR.$index,
            name: $name,
            arguments: is_array($call['args'] ?? null) ? $call['args'] : [],
        );
    }

    private function nameFromId(string $id): string
    {
        $separator = strrpos($id, self::ID_SEPARATOR);

        return $separator === false ? $id : substr($id, 0, $separator);
    }

    /**
     * `promptTokenCount` INCLUDES cached tokens, unlike Anthropic's, so the
     * cached figure is reported alongside rather than added to it.
     *
     * @param array<string, mixed> $usage
     */
    private function parseUsage(array $usage): UsageData
    {
        return new UsageData(
            inputTokens: (int) ($usage['promptTokenCount'] ?? 0),
            outputTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
            cachedInputTokens: (int) ($usage['cachedContentTokenCount'] ?? 0),
            reasoningTokens: (int) ($usage['thoughtsTokenCount'] ?? 0),
        );
    }

    private function mapFinishReason(string $reason): FinishReason
    {
        return match ($reason) {
            'STOP' => FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT' => FinishReason::ContentFilter,
            default => FinishReason::Stop,
        };
    }

    // --------------------------------------------------------------- transport

    private function send(ChatRequest $request, bool $stream): Response
    {
        // The model is part of the path, which is why this adapter builds its
        // URL per call rather than posting to one endpoint.
        $method = $stream ? 'streamGenerateContent' : 'generateContent';
        $url = "/models/{$request->model}:{$method}".($stream ? '?alt=sse' : '');

        try {
            $client = $this->client();

            if ($stream) {
                $client = $client->withOptions(['stream' => true]);
            }

            $response = $client->post($url, $this->payload($request));
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

        $apiKey = $this->credentials?->resolve($this->key)?->secret()
            ?? $this->config['api_key']
            ?? null;

        if (is_string($apiKey) && $apiKey !== '') {
            // The header, never the `?key=` query parameter Google's own
            // examples use: a query string ends up in proxy logs, browser
            // history and error reports.
            $request = $request->withHeaders(['x-goog-api-key' => $apiKey]);
        }

        return $request;
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
