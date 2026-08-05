<?php

declare(strict_types=1);

namespace Pandora\Pandora\Testing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pandora\Pandora\Exceptions\Provider\ContextOverflow;
use Pandora\Pandora\Exceptions\Provider\ProviderAuthenticationFailed;
use Pandora\Pandora\Exceptions\Provider\ProviderException;
use Pandora\Pandora\Exceptions\Provider\ProviderRateLimited;
use Pandora\Pandora\Exceptions\Provider\ProviderRejectedRequest;
use Pandora\Pandora\Exceptions\Provider\ProviderTimeout;
use Pandora\Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Pandora\Providers\Data\ChatMessage;
use Pandora\Pandora\Providers\Data\ChatRequest;
use Pandora\Pandora\Providers\Data\FinishReason;
use Pandora\Pandora\Providers\Data\StreamDelta;
use Pandora\Pandora\Providers\Data\StreamDeltaType;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Providers\Data\ToolDefinition;
use Pandora\Pandora\Providers\Data\UsageData;

/**
 * The suite every adapter must pass.
 *
 * One file, N adapters. A Pandora adapter is not "done" because it worked
 * against the one model somebody tried by hand -- it is done when this suite
 * passes, and the suite runs entirely against recorded fixtures, so writing
 * an adapter never requires anybody to hold a paid API key.
 *
 * Usage, from a Pest test file:
 *
 *     ProviderContractTests::for(new OpenAiCompatibleFixtures);
 *
 * Every assertion below is about PANDORA's behaviour -- normalisation,
 * classification, ordering, secret handling. The vendor's dialect comes from
 * the ProviderFixtures implementation, which is the only part an adapter
 * author writes twice.
 *
 * The helpers are public and called by explicit class name because Pest binds
 * each test closure to the TestCase, which moves `self::` out from under us.
 *
 * @see docs/architecture/provider-model.md section 6
 */
final class ProviderContractTests
{
    public static function for(ProviderFixtures $fixtures): void
    {
        self::completions($fixtures);
        self::streaming($fixtures);
        self::toolUse($fixtures);
        self::usage($fixtures);
        self::errors($fixtures);
        self::secrets($fixtures);
    }

    // ------------------------------------------------------------ completions

    private static function completions(ProviderFixtures $fixtures): void
    {
        it('completes a request and returns a normalised response', function () use ($fixtures): void {
            ProviderContractTests::fake($fixtures, $fixtures->completionResponse('The order shipped on Tuesday.'));

            $response = $fixtures->make()->chat(ProviderContractTests::request($fixtures));

            expect($response->content)->toBe('The order shipped on Tuesday.')
                ->and($response->finishReason)->toBe(FinishReason::Stop)
                ->and($response->isFinal())->toBeTrue()
                // Duration is measured by the adapter, not reported by the
                // vendor, so it is present on every response.
                ->and($response->usage->durationMs)->toBeGreaterThanOrEqual(0);
        });

        it('sends the model, the conversation and the system prompt where the vendor expects them', function () use ($fixtures): void {
            ProviderContractTests::fake($fixtures, $fixtures->completionResponse('Noted.'));

            $fixtures->make()->chat(new ChatRequest(
                model: $fixtures->model(),
                messages: [
                    ChatMessage::system('You are terse.'),
                    ChatMessage::user('Where is order 1234?'),
                    ChatMessage::assistant('Checking.'),
                    ChatMessage::user('Thanks.'),
                ],
            ));

            $body = ProviderContractTests::lastRequestBody();

            expect($fixtures->sentModel($body))->toBe($fixtures->model())
                ->and($fixtures->sentSystemPrompt($body))->toBe('You are terse.');

            // The system message is excluded here: some vendors carry it
            // top-level rather than in the conversation, and both are right.
            $conversation = array_values(array_filter(
                $fixtures->sentMessages($body),
                static fn (array $message): bool => $message['role'] !== 'system',
            ));

            expect($conversation)->toBe([
                ['role' => 'user', 'content' => 'Where is order 1234?'],
                ['role' => 'assistant', 'content' => 'Checking.'],
                ['role' => 'user', 'content' => 'Thanks.'],
            ]);
        });

        it('normalises a response truncated by the output limit to a length finish', function () use ($fixtures): void {
            ProviderContractTests::fake($fixtures, $fixtures->truncatedResponse('A truncated answ'));

            $response = $fixtures->make()->chat(ProviderContractTests::request($fixtures));

            expect($response->finishReason)->toBe(FinishReason::Length)
                ->and($response->content)->toBe('A truncated answ');
        });
    }

    // -------------------------------------------------------------- streaming

    private static function streaming(ProviderFixtures $fixtures): void
    {
        it('streams deltas in order and returns the same content a plain call would', function () use ($fixtures): void {
            ProviderContractTests::fakeStream($fixtures, $fixtures->streamResponse('Hello there, friend.'));

            $text = '';
            $types = [];

            $response = $fixtures->make()->stream(
                ProviderContractTests::request($fixtures)->withStreaming(),
                function (StreamDelta $delta) use (&$text, &$types): void {
                    $types[] = $delta->type;

                    if ($delta->type === StreamDeltaType::Text) {
                        $text .= $delta->text;
                    }
                },
            );

            expect($text)->toBe('Hello there, friend.')
                // The caller relies on the RETURN value, never on having
                // accumulated the deltas itself.
                ->and($response->content)->toBe('Hello there, friend.')
                // More than one text delta, or nothing is actually streaming.
                ->and(count(array_filter($types, static fn (StreamDeltaType $t): bool => $t === StreamDeltaType::Text)))
                ->toBeGreaterThan(1)
                ->and(end($types))->toBe(StreamDeltaType::Done);
        });

        it('emits usage before done, so a consumer sees it', function () use ($fixtures): void {
            ProviderContractTests::fakeStream($fixtures, $fixtures->streamResponse(
                'Hi.',
                usage: new UsageData(inputTokens: 12, outputTokens: 3),
            ));

            $usage = null;
            $afterUsage = [];

            $fixtures->make()->stream(
                ProviderContractTests::request($fixtures)->withStreaming(),
                function (StreamDelta $delta) use (&$usage, &$afterUsage): void {
                    if ($delta->type === StreamDeltaType::Usage) {
                        $usage = $delta->usage;
                    } elseif ($usage !== null) {
                        $afterUsage[] = $delta->type;
                    }
                },
            );

            expect($usage?->inputTokens)->toBe(12)
                ->and($afterUsage)->toBe([StreamDeltaType::Done]);
        });

        it('lets a caller abort a stream without turning it into a provider failure', function () use ($fixtures): void {
            ProviderContractTests::fakeStream(
                $fixtures,
                $fixtures->streamResponse('One two three four five six seven eight nine ten.'),
            );

            $seen = 0;
            $thrown = null;

            try {
                $fixtures->make()->stream(
                    ProviderContractTests::request($fixtures)->withStreaming(),
                    function (StreamDelta $delta) use (&$seen): void {
                        if ($delta->type !== StreamDeltaType::Text) {
                            return;
                        }

                        $seen++;

                        if ($seen === 2) {
                            throw new StoppedByCaller;
                        }
                    },
                );
            } catch (\Throwable $e) {
                $thrown = $e;
            }

            // A cancellation is the caller's decision and must reach the
            // caller unchanged. Wrapping it in a provider error would turn
            // "the user pressed stop" into "the model failed".
            expect($thrown)->toBeInstanceOf(StoppedByCaller::class)
                // And consumption stopped there rather than draining the
                // whole stream first.
                ->and($seen)->toBe(2);
        });
    }

    // --------------------------------------------------------------- tool use

    private static function toolUse(ProviderFixtures $fixtures): void
    {
        it('advertises tool definitions in the vendor\'s shape', function () use ($fixtures): void {
            ProviderContractTests::fake($fixtures, $fixtures->completionResponse('Fine.'));

            $fixtures->make()->chat(ProviderContractTests::request($fixtures)->withTools([
                new ToolDefinition('lookup_order', 'Look up an order.', [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'string']],
                    'required' => ['id'],
                ]),
                new ToolDefinition('cancel_order', 'Cancel an order.', ['type' => 'object']),
            ]));

            expect($fixtures->sentToolNames(ProviderContractTests::lastRequestBody()))
                ->toBe(['lookup_order', 'cancel_order']);
        });

        it('returns a requested tool call with its id, name and decoded arguments', function () use ($fixtures): void {
            ProviderContractTests::fake($fixtures, $fixtures->toolCallResponse([
                new ToolCall('call_a1', 'lookup_order', ['id' => '1234']),
            ]));

            $response = $fixtures->make()->chat(ProviderContractTests::request($fixtures));

            expect($response->finishReason)->toBe(FinishReason::ToolCalls)
                ->and($response->isFinal())->toBeFalse()
                ->and($response->toolCalls)->toHaveCount(1)
                ->and($response->toolCalls[0]->id)->toBe('call_a1')
                ->and($response->toolCalls[0]->name)->toBe('lookup_order')
                ->and($response->toolCalls[0]->arguments)->toBe(['id' => '1234']);
        });

        it('assembles multiple tool calls in one turn with their ids intact', function () use ($fixtures): void {
            ProviderContractTests::fake($fixtures, $fixtures->toolCallResponse([
                new ToolCall('call_a1', 'lookup_order', ['id' => '1']),
                new ToolCall('call_b2', 'lookup_order', ['id' => '2']),
                new ToolCall('call_c3', 'cancel_order', ['id' => '3']),
            ]));

            $response = $fixtures->make()->chat(ProviderContractTests::request($fixtures));

            expect(array_map(static fn (ToolCall $c): string => $c->id, $response->toolCalls))
                ->toBe(['call_a1', 'call_b2', 'call_c3'])
                ->and($response->toolCalls[2]->arguments)->toBe(['id' => '3']);
        });

        it('assembles a tool call streamed as argument fragments', function () use ($fixtures): void {
            ProviderContractTests::fakeStream($fixtures, $fixtures->streamResponse('', [
                new ToolCall('call_a1', 'lookup_order', ['id' => '1234']),
            ]));

            $streamed = [];

            $response = $fixtures->make()->stream(
                ProviderContractTests::request($fixtures)->withStreaming(),
                function (StreamDelta $delta) use (&$streamed): void {
                    if ($delta->type === StreamDeltaType::ToolCall && $delta->toolCall !== null) {
                        $streamed[] = $delta->toolCall;
                    }
                },
            );

            expect($response->finishReason)->toBe(FinishReason::ToolCalls)
                ->and($response->toolCalls)->toHaveCount(1)
                ->and($response->toolCalls[0]->name)->toBe('lookup_order')
                ->and($response->toolCalls[0]->arguments)->toBe(['id' => '1234'])
                ->and($streamed)->toHaveCount(1);
        });

        it('replays a tool result against the call that asked for it', function () use ($fixtures): void {
            ProviderContractTests::fake($fixtures, $fixtures->completionResponse('It shipped on Tuesday.'));

            $call = new ToolCall('call_a1', 'lookup_order', ['id' => '1234']);

            $fixtures->make()->chat(new ChatRequest(
                model: $fixtures->model(),
                messages: [
                    ChatMessage::user('Where is order 1234?'),
                    ChatMessage::assistantToolCalls('', [$call]),
                    ChatMessage::tool('call_a1', '{"status":"shipped"}', 'lookup_order'),
                ],
            ));

            expect($fixtures->sentToolResultIds(ProviderContractTests::lastRequestBody()))
                ->toBe(['call_a1']);
        });
    }

    // ------------------------------------------------------------------ usage

    private static function usage(ProviderFixtures $fixtures): void
    {
        it('normalises usage out of the vendor\'s field names', function () use ($fixtures): void {
            ProviderContractTests::fake($fixtures, $fixtures->completionResponse(
                'Done.',
                inputTokens: 1_200,
                outputTokens: 340,
                cachedInputTokens: 800,
            ));

            $usage = $fixtures->make()->chat(ProviderContractTests::request($fixtures))->usage;

            expect($usage->inputTokens)->toBe(1_200)
                ->and($usage->outputTokens)->toBe(340)
                ->and($usage->cachedInputTokens)->toBe(800)
                ->and($usage->totalTokens())->toBe(1_540)
                ->and($usage->requests)->toBe(1);
        });
    }

    // ----------------------------------------------------------------- errors

    private static function errors(ProviderFixtures $fixtures): void
    {
        it('classifies every documented failure behaviourally', function (
            int $status,
            string $expected,
            bool $retryable,
            bool $failover,
        ) use ($fixtures): void {
            $exception = ProviderContractTests::failWith(
                $fixtures,
                $status,
                $fixtures->errorResponse('Something went wrong.'),
            );

            // Exact class, not instanceof: the hierarchy is final and a
            // sibling exception with the same retry behaviour would still be
            // the wrong answer.
            expect($exception::class)->toBe($expected)
                ->and($exception->isRetryable())->toBe($retryable)
                ->and($exception->allowsFailover())->toBe($failover);
        })->with([
            'bad credential' => [401, ProviderAuthenticationFailed::class, false, true],
            'forbidden' => [403, ProviderAuthenticationFailed::class, false, true],
            'rate limited' => [429, ProviderRateLimited::class, true, true],
            'request timeout' => [408, ProviderTimeout::class, true, true],
            'gateway timeout' => [504, ProviderTimeout::class, true, true],
            'server error' => [500, ProviderUnavailable::class, true, true],
            'bad request' => [400, ProviderRejectedRequest::class, false, false],
        ]);

        it('classifies a context-window failure as ContextOverflow, not a generic rejection', function () use ($fixtures): void {
            $exception = ProviderContractTests::failWith(
                $fixtures,
                400,
                $fixtures->errorResponse($fixtures->contextOverflowMessage()),
            );

            expect($exception)->toBeInstanceOf(ContextOverflow::class)
                // Retrying is pointless; a larger-context model is not.
                ->and($exception->isRetryable())->toBeFalse()
                ->and($exception->allowsFailover())->toBeTrue();
        });

        it('carries the provider and the model on the exception, for the trace', function () use ($fixtures): void {
            $exception = ProviderContractTests::failWith($fixtures, 500, $fixtures->errorResponse('Down.'));

            expect($exception->provider)->toBe($fixtures->key())
                ->and($exception->model)->toBe($fixtures->model());
        });

        it('fails as a provider error rather than a PHP error on an unparseable body', function () use ($fixtures): void {
            Http::fake([
                $fixtures->endpointPattern() => Http::response($fixtures->malformedBody(), 200),
            ]);

            expect(fn () => $fixtures->make()->chat(ProviderContractTests::request($fixtures)))
                ->toThrow(ProviderUnavailable::class);
        });

        it('reports an unreachable host as a timeout rather than an unhandled exception', function () use ($fixtures): void {
            Http::fake(fn (): never => throw new ConnectionException('Connection refused'));

            expect(fn () => $fixtures->make()->chat(ProviderContractTests::request($fixtures)))
                ->toThrow(ProviderTimeout::class);
        });
    }

    // ---------------------------------------------------------------- secrets

    private static function secrets(ProviderFixtures $fixtures): void
    {
        it('sends the credential in a header and nowhere else', function () use ($fixtures): void {
            ProviderContractTests::fake($fixtures, $fixtures->completionResponse('Fine.'));

            $fixtures->make()->chat(ProviderContractTests::request($fixtures));

            $request = ProviderContractTests::lastRequest();

            expect(implode(' ', $request->header($fixtures->credentialHeader())))
                ->toContain($fixtures->apiKey())
                ->and($request->body())->not->toContain($fixtures->apiKey())
                ->and($request->url())->not->toContain($fixtures->apiKey());
        });

        it('keeps the credential out of the message it shows a user', function () use ($fixtures): void {
            // A provider echoing the key back in its error text is not
            // hypothetical: OpenAI does exactly that on a 401.
            $exception = ProviderContractTests::failWith(
                $fixtures,
                401,
                $fixtures->errorResponse('Incorrect API key provided: '.$fixtures->apiKey()),
            );

            expect($exception->userMessage())->not->toContain($fixtures->apiKey());
        });
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @internal
     *
     * @param array<string, mixed> $body
     */
    public static function fake(ProviderFixtures $fixtures, array $body, int $status = 200): void
    {
        Http::fake([$fixtures->endpointPattern() => Http::response($body, $status)]);
    }

    /**
     * @internal
     */
    public static function fakeStream(ProviderFixtures $fixtures, string $rawBody): void
    {
        Http::fake([
            $fixtures->endpointPattern() => Http::response(
                $rawBody,
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $body
     */
    public static function failWith(ProviderFixtures $fixtures, int $status, array $body): ProviderException
    {
        self::fake($fixtures, $body, $status);

        try {
            $fixtures->make()->chat(self::request($fixtures));
        } catch (ProviderException $e) {
            return $e;
        }

        throw new \RuntimeException(
            "Adapter [{$fixtures->key()}] did not classify an HTTP {$status} as a ProviderException.",
        );
    }

    /**
     * @internal
     */
    public static function request(ProviderFixtures $fixtures): ChatRequest
    {
        return new ChatRequest(
            model: $fixtures->model(),
            messages: [ChatMessage::user('Where is order 1234?')],
        );
    }

    /**
     * @internal
     */
    public static function lastRequest(): Request
    {
        /** @var array{0: Request}|null $recorded */
        $recorded = Http::recorded()->last();

        if ($recorded === null) {
            throw new \RuntimeException('The adapter made no HTTP request.');
        }

        return $recorded[0];
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public static function lastRequestBody(): array
    {
        /** @var array<string, mixed> $body */
        $body = self::lastRequest()->data();

        return $body;
    }
}
