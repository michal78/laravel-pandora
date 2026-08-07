<?php

declare(strict_types=1);

namespace Pandora\Tests\Providers\Contract;

use Illuminate\Http\Client\Factory as HttpFactory;
use Pandora\Contracts\StreamingProvider;
use Pandora\Providers\Adapters\GeminiProvider;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\Data\UsageData;
use Pandora\Testing\ProviderFixtures;

/**
 * Gemini's dialect: the model in the path, `model` for the assistant role,
 * `functionDeclarations` for tools, `usageMetadata` for usage -- and function
 * calls with no ids at all, which is why this fixture's `expectedToolCallId`
 * differs from every other one.
 */
final class GeminiFixtures implements ProviderFixtures
{
    public function key(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return 'gemini-2.5-flash';
    }

    public function endpointPattern(): string
    {
        return 'generativelanguage.test/*';
    }

    public function apiKey(): string
    {
        return 'AIza-contract-suite-key';
    }

    public function credentialHeader(): string
    {
        return 'x-goog-api-key';
    }

    public function make(): StreamingProvider
    {
        return new GeminiProvider(
            key: $this->key(),
            config: [
                'base_url' => 'https://generativelanguage.test/v1beta',
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
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => $text]]],
                'finishReason' => 'STOP',
                'index' => 0,
            ]],
            'usageMetadata' => [
                'promptTokenCount' => $inputTokens,
                'candidatesTokenCount' => $outputTokens,
                'cachedContentTokenCount' => $cachedInputTokens,
                'totalTokenCount' => $inputTokens + $outputTokens,
            ],
            'modelVersion' => $this->model(),
            'responseId' => 'resp-contract',
        ];
    }

    public function truncatedResponse(string $text): array
    {
        $body = $this->completionResponse($text);
        $body['candidates'][0]['finishReason'] = 'MAX_TOKENS';

        return $body;
    }

    public function toolCallResponse(array $calls): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => array_map(static fn (ToolCall $call): array => [
                        'functionCall' => ['name' => $call->name, 'args' => $call->arguments],
                    ], $calls),
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 15],
            'responseId' => 'resp-contract',
        ];
    }

    public function streamResponse(string $text, array $toolCalls = [], ?UsageData $usage = null): string
    {
        $chunks = [];

        foreach ($this->chunk($text) as $piece) {
            $chunks[] = [
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $piece]]]]],
                'responseId' => 'resp-stream',
            ];
        }

        foreach ($toolCalls as $call) {
            $chunks[] = [
                'candidates' => [['content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => $call->name, 'args' => $call->arguments],
                ]]]]],
                'responseId' => 'resp-stream',
            ];
        }

        // Usage is cumulative and repeated; the final chunk carries the truth.
        $chunks[] = [
            'candidates' => [['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'STOP']],
            'usageMetadata' => [
                'promptTokenCount' => $usage?->inputTokens ?? 0,
                'candidatesTokenCount' => $usage?->outputTokens ?? 0,
            ],
            'responseId' => 'resp-stream',
        ];

        $body = '';

        foreach ($chunks as $chunk) {
            $body .= 'data: '.json_encode($chunk)."\n\n";
        }

        return $body;
    }

    public function errorResponse(string $message, ?string $code = null): array
    {
        return ['error' => array_filter([
            'code' => 400,
            'message' => $message,
            'status' => $code ?? 'INVALID_ARGUMENT',
        ])];
    }

    public function contextOverflowMessage(): string
    {
        return 'The input token count (1348291) exceeds the maximum number of tokens allowed (1048576).';
    }

    public function malformedBody(): string
    {
        return '{"candidates":[{"content":{"parts":[{"te';
    }

    public function expectedToolCallId(ToolCall $call, int $index): string
    {
        // Gemini issues none, so the adapter synthesises one. Without it, two
        // parallel calls to the same function could not be answered
        // separately.
        return $call->name.'#'.$index;
    }

    public function correlationFor(ToolCall $call): string
    {
        return $call->name;
    }

    public function sentModel(array $body, string $url): ?string
    {
        return preg_match('#/models/([^:]+):#', $url, $matches) === 1
            ? $matches[1]
            : null;
    }

    public function sentMessages(array $body): array
    {
        $messages = [];

        /** @var array<int, array<string, mixed>> $contents */
        $contents = is_array($body['contents'] ?? null) ? $body['contents'] : [];

        foreach ($contents as $content) {
            $text = '';

            /** @var array<int, mixed> $parts */
            $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];

            foreach ($parts as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }

            $messages[] = [
                // `model` is Gemini's name for the assistant; the suite speaks
                // Pandora's vocabulary.
                'role' => ($content['role'] ?? '') === 'model' ? 'assistant' : 'user',
                'content' => $text,
            ];
        }

        return $messages;
    }

    public function sentToolNames(array $body): array
    {
        $names = [];

        /** @var array<int, mixed> $declarations */
        $declarations = is_array($body['tools'][0]['functionDeclarations'] ?? null)
            ? $body['tools'][0]['functionDeclarations']
            : [];

        foreach ($declarations as $declaration) {
            if (is_array($declaration) && is_string($declaration['name'] ?? null)) {
                $names[] = $declaration['name'];
            }
        }

        return $names;
    }

    public function sentSystemPrompt(array $body): ?string
    {
        $text = $body['systemInstruction']['parts'][0]['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    public function sentToolResultCorrelations(array $body): array
    {
        $names = [];

        /** @var array<int, array<string, mixed>> $contents */
        $contents = is_array($body['contents'] ?? null) ? $body['contents'] : [];

        foreach ($contents as $content) {
            /** @var array<int, mixed> $parts */
            $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];

            foreach ($parts as $part) {
                if (is_array($part) && is_string($part['functionResponse']['name'] ?? null)) {
                    $names[] = $part['functionResponse']['name'];
                }
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function chunk(string $text): array
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
