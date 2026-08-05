<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests\Providers\Contract;

use Illuminate\Http\Client\Factory as HttpFactory;
use Pandora\Pandora\Contracts\StreamingProvider;
use Pandora\Pandora\Providers\Adapters\OpenAiCompatibleProvider;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Providers\Data\UsageData;
use Pandora\Pandora\Testing\ProviderFixtures;

/**
 * OpenAI's chat-completions dialect, as spoken by OpenAI, Ollama, OpenRouter,
 * vLLM, llama.cpp and LM Studio.
 *
 * Every body here is the shape those servers actually return. Where they
 * differ from one another -- and they do -- the subclasses in this directory
 * override the difference rather than the suite bending to accommodate it.
 */
class OpenAiCompatibleFixtures implements ProviderFixtures
{
    public function key(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return 'gpt-4o-mini';
    }

    public function baseUrl(): string
    {
        return 'https://api.openai.test/v1';
    }

    public function endpointPattern(): string
    {
        return 'api.openai.test/*';
    }

    public function apiKey(): string
    {
        return 'sk-contract-suite-key';
    }

    public function credentialHeader(): string
    {
        return 'Authorization';
    }

    public function make(): StreamingProvider
    {
        return new OpenAiCompatibleProvider(
            key: $this->key(),
            config: [
                'base_url' => $this->baseUrl(),
                'api_key' => $this->apiKey(),
            ],
            http: app(HttpFactory::class),
        );
    }

    public function completionResponse(
        string $text,
        int $inputTokens = 11,
        int $outputTokens = 7,
        int $cachedInputTokens = 0,
    ): array {
        return [
            'id' => 'chatcmpl-contract',
            'object' => 'chat.completion',
            'model' => $this->model(),
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
                'prompt_tokens_details' => ['cached_tokens' => $cachedInputTokens],
            ],
        ];
    }

    public function truncatedResponse(string $text): array
    {
        $body = $this->completionResponse($text);
        $body['choices'][0]['finish_reason'] = 'length';

        return $body;
    }

    public function toolCallResponse(array $calls): array
    {
        return [
            'id' => 'chatcmpl-contract',
            'model' => $this->model(),
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => array_map(static fn (ToolCall $call): array => [
                        'id' => $call->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $call->name,
                            'arguments' => (string) json_encode($call->arguments),
                        ],
                    ], $calls),
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 15],
        ];
    }

    public function streamResponse(string $text, array $toolCalls = [], ?UsageData $usage = null): string
    {
        $events = [];

        // Word by word, which is close enough to how a real server chunks and
        // guarantees the suite sees more than one text delta.
        foreach ($this->chunk($text) as $chunk) {
            $events[] = ['choices' => [['index' => 0, 'delta' => ['content' => $chunk]]]];
        }

        foreach ($toolCalls as $index => $call) {
            $arguments = (string) json_encode($call->arguments);

            $events[] = ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                'index' => $index,
                'id' => $call->id,
                'type' => 'function',
                'function' => ['name' => $call->name, 'arguments' => ''],
            ]]]]]];

            // Arguments arrive as JSON fragments across several events, which
            // is the part of the protocol adapters most often get wrong.
            foreach (str_split($arguments, 4) as $fragment) {
                $events[] = ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
                    'index' => $index,
                    'function' => ['arguments' => $fragment],
                ]]]]]];
            }
        }

        $events[] = ['choices' => [[
            'index' => 0,
            'delta' => [],
            'finish_reason' => $toolCalls === [] ? 'stop' : 'tool_calls',
        ]]];

        if ($usage !== null) {
            $events[] = [
                'choices' => [],
                'usage' => [
                    'prompt_tokens' => $usage->inputTokens,
                    'completion_tokens' => $usage->outputTokens,
                ],
            ];
        }

        $body = '';

        foreach ($events as $event) {
            $body .= 'data: '.json_encode($event + ['id' => 'chatcmpl-stream'])."\n\n";
        }

        return $body."data: [DONE]\n\n";
    }

    public function errorResponse(string $message, ?string $code = null): array
    {
        return ['error' => array_filter([
            'message' => $message,
            'type' => $code,
            'code' => $code,
        ], static fn (?string $value): bool => $value !== null)];
    }

    public function contextOverflowMessage(): string
    {
        return "This model's maximum context length is 128000 tokens. However, your messages resulted in 140233 tokens.";
    }

    public function malformedBody(): string
    {
        // A response cut off mid-flight: valid JSON never arrives.
        return '{"id":"chatcmpl-truncated","choices":[{"message":{"role":"assist';
    }

    public function expectedToolCallId(ToolCall $call, int $index): string
    {
        return $call->id;
    }

    public function correlationFor(ToolCall $call): string
    {
        return $call->id;
    }

    public function sentModel(array $body, string $url): ?string
    {
        return is_string($body['model'] ?? null) ? $body['model'] : null;
    }

    public function sentMessages(array $body): array
    {
        $messages = [];

        /** @var array<int, array<string, mixed>> $sent */
        $sent = is_array($body['messages'] ?? null) ? $body['messages'] : [];

        foreach ($sent as $message) {
            $messages[] = [
                'role' => is_string($message['role'] ?? null) ? $message['role'] : '',
                'content' => is_string($message['content'] ?? null) ? $message['content'] : '',
            ];
        }

        return $messages;
    }

    public function sentToolNames(array $body): array
    {
        $names = [];

        /** @var array<int, array<string, mixed>> $tools */
        $tools = is_array($body['tools'] ?? null) ? $body['tools'] : [];

        foreach ($tools as $tool) {
            /** @var array<string, mixed> $function */
            $function = is_array($tool['function'] ?? null) ? $tool['function'] : [];

            if (is_string($function['name'] ?? null)) {
                $names[] = $function['name'];
            }
        }

        return $names;
    }

    public function sentSystemPrompt(array $body): ?string
    {
        foreach ($this->sentMessages($body) as $message) {
            if ($message['role'] === 'system') {
                return $message['content'];
            }
        }

        return null;
    }

    public function sentToolResultCorrelations(array $body): array
    {
        $ids = [];

        /** @var array<int, array<string, mixed>> $sent */
        $sent = is_array($body['messages'] ?? null) ? $body['messages'] : [];

        foreach ($sent as $message) {
            if (($message['role'] ?? null) === 'tool' && is_string($message['tool_call_id'] ?? null)) {
                $ids[] = $message['tool_call_id'];
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    protected function chunk(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $words = explode(' ', $text);

        return array_values(array_map(
            static fn (string $word, int $index): string => $index === 0 ? $word : ' '.$word,
            $words,
            array_keys($words),
        ));
    }
}
