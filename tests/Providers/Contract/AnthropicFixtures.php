<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests\Providers\Contract;

use Illuminate\Http\Client\Factory as HttpFactory;
use Pandora\Pandora\Contracts\StreamingProvider;
use Pandora\Pandora\Providers\Adapters\AnthropicProvider;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Providers\Data\UsageData;
use Pandora\Pandora\Testing\ProviderFixtures;

/**
 * Anthropic's Messages dialect: a top-level system prompt, typed content
 * blocks, tool results carried inside a user turn, and usage that reports
 * cache reads separately from fresh input.
 */
final class AnthropicFixtures implements ProviderFixtures
{
    public function key(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return 'claude-sonnet-4-5';
    }

    public function endpointPattern(): string
    {
        return 'api.anthropic.test/*';
    }

    public function apiKey(): string
    {
        return 'sk-ant-contract-suite-key';
    }

    public function credentialHeader(): string
    {
        return 'x-api-key';
    }

    public function make(): StreamingProvider
    {
        return new AnthropicProvider(
            key: $this->key(),
            config: [
                'base_url' => 'https://api.anthropic.test/v1',
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
            'id' => 'msg_contract',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $this->model(),
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cache_read_input_tokens' => $cachedInputTokens,
                'cache_creation_input_tokens' => 0,
            ],
        ];
    }

    public function truncatedResponse(string $text): array
    {
        $body = $this->completionResponse($text);
        $body['stop_reason'] = 'max_tokens';

        return $body;
    }

    public function toolCallResponse(array $calls): array
    {
        return [
            'id' => 'msg_contract',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $this->model(),
            'content' => array_map(static fn (ToolCall $call): array => [
                'type' => 'tool_use',
                'id' => $call->id,
                'name' => $call->name,
                'input' => $call->arguments,
            ], $calls),
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 15],
        ];
    }

    public function streamResponse(string $text, array $toolCalls = [], ?UsageData $usage = null): string
    {
        $events = [];

        $events[] = ['message_start', [
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_stream',
                'type' => 'message',
                'role' => 'assistant',
                'model' => $this->model(),
                'content' => [],
                'usage' => [
                    'input_tokens' => $usage?->inputTokens ?? 0,
                    'output_tokens' => 0,
                ],
            ],
        ]];

        $index = 0;

        if ($text !== '') {
            $events[] = ['content_block_start', [
                'type' => 'content_block_start',
                'index' => $index,
                'content_block' => ['type' => 'text', 'text' => ''],
            ]];

            foreach ($this->chunk($text) as $chunk) {
                $events[] = ['content_block_delta', [
                    'type' => 'content_block_delta',
                    'index' => $index,
                    'delta' => ['type' => 'text_delta', 'text' => $chunk],
                ]];
            }

            $events[] = ['content_block_stop', ['type' => 'content_block_stop', 'index' => $index]];
            $index++;
        }

        foreach ($toolCalls as $call) {
            $events[] = ['content_block_start', [
                'type' => 'content_block_start',
                'index' => $index,
                'content_block' => [
                    'type' => 'tool_use',
                    'id' => $call->id,
                    'name' => $call->name,
                    'input' => new \stdClass,
                ],
            ]];

            // Arguments arrive as JSON fragments, which is the part adapters
            // most often get wrong.
            foreach (str_split((string) json_encode($call->arguments), 4) as $fragment) {
                $events[] = ['content_block_delta', [
                    'type' => 'content_block_delta',
                    'index' => $index,
                    'delta' => ['type' => 'input_json_delta', 'partial_json' => $fragment],
                ]];
            }

            $events[] = ['content_block_stop', ['type' => 'content_block_stop', 'index' => $index]];
            $index++;
        }

        $events[] = ['message_delta', [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => $toolCalls === [] ? 'end_turn' : 'tool_use'],
            'usage' => ['output_tokens' => $usage?->outputTokens ?? 0],
        ]];

        $events[] = ['message_stop', ['type' => 'message_stop']];

        $body = '';

        foreach ($events as [$name, $payload]) {
            $body .= "event: {$name}\ndata: ".json_encode($payload)."\n\n";
        }

        return $body;
    }

    public function errorResponse(string $message, ?string $code = null): array
    {
        return [
            'type' => 'error',
            'error' => array_filter([
                'type' => $code ?? 'invalid_request_error',
                'message' => $message,
            ]),
        ];
    }

    public function contextOverflowMessage(): string
    {
        return 'prompt is too long: 214233 tokens > 200000 maximum';
    }

    public function malformedBody(): string
    {
        return '{"id":"msg_truncated","type":"message","content":[{"type":"te';
    }

    public function sentModel(array $body): ?string
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
                'content' => $this->textOf($message),
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
            if (is_string($tool['name'] ?? null)) {
                $names[] = $tool['name'];
            }
        }

        return $names;
    }

    public function sentSystemPrompt(array $body): ?string
    {
        return is_string($body['system'] ?? null) ? $body['system'] : null;
    }

    public function sentToolResultIds(array $body): array
    {
        $ids = [];

        /** @var array<int, array<string, mixed>> $sent */
        $sent = is_array($body['messages'] ?? null) ? $body['messages'] : [];

        foreach ($sent as $message) {
            /** @var array<int, mixed> $blocks */
            $blocks = is_array($message['content'] ?? null) ? $message['content'] : [];

            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'tool_result'
                    && is_string($block['tool_use_id'] ?? null)) {
                    $ids[] = $block['tool_use_id'];
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function textOf(array $message): string
    {
        /** @var array<int, mixed> $blocks */
        $blocks = is_array($message['content'] ?? null) ? $message['content'] : [];

        $text = '';

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function chunk(string $text): array
    {
        $words = explode(' ', $text);

        return array_values(array_map(
            static fn (string $word, int $index): string => $index === 0 ? $word : ' '.$word,
            $words,
            array_keys($words),
        ));
    }
}
