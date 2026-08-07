<?php

declare(strict_types=1);

namespace Pandora\Providers\Adapters;

use Pandora\Contracts\StreamingProvider;
use Pandora\Providers\Data\ChatRequest;
use Pandora\Providers\Data\ChatResponse;
use Pandora\Providers\Data\FinishReason;
use Pandora\Providers\Data\ProviderCapabilities;
use Pandora\Providers\Data\ProviderHealth;
use Pandora\Providers\Data\StreamDelta;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\Data\UsageData;

/**
 * A deterministic in-memory provider for local development and tests.
 *
 * Scripted responses are consumed in order; when the script is exhausted it
 * echoes the last user message, so a fresh install has something that works
 * before any credentials exist.
 *
 * Tests must never call a paid API -- this and the recorded-fixture adapters
 * are how that rule is kept.
 */
final class FakeProvider implements StreamingProvider
{
    /** @var list<ChatResponse|\Throwable> */
    private array $script = [];

    private int $cursor = 0;

    /** @var list<ChatRequest> */
    private array $received = [];

    /** Delay between streamed chunks, in microseconds. Zero in tests. */
    private int $chunkDelayMicroseconds = 0;

    public function __construct(
        private readonly string $key = 'fake',
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
        );
    }

    public function health(): ProviderHealth
    {
        return ProviderHealth::healthy(0);
    }

    /**
     * Queue a plain text reply.
     */
    public function willRespondWith(string $content, ?UsageData $usage = null): self
    {
        $this->script[] = new ChatResponse(
            content: $content,
            finishReason: FinishReason::Stop,
            usage: $usage ?? $this->estimateUsage($content),
        );

        return $this;
    }

    /**
     * Queue a reply requesting tool calls.
     *
     * @param list<ToolCall> $toolCalls
     */
    public function willRequestTools(array $toolCalls, string $content = ''): self
    {
        $this->script[] = new ChatResponse(
            content: $content,
            finishReason: FinishReason::ToolCalls,
            usage: $this->estimateUsage($content),
            toolCalls: $toolCalls,
        );

        return $this;
    }

    /**
     * Queue a failure, so error classification and failover can be tested.
     */
    public function willThrow(\Throwable $exception): self
    {
        $this->script[] = $exception;

        return $this;
    }

    public function withChunkDelay(int $microseconds): self
    {
        $this->chunkDelayMicroseconds = $microseconds;

        return $this;
    }

    /**
     * @return list<ChatRequest>
     */
    public function receivedRequests(): array
    {
        return $this->received;
    }

    public function reset(): self
    {
        $this->script = [];
        $this->cursor = 0;
        $this->received = [];

        return $this;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->received[] = $request;

        return $this->next($request);
    }

    public function stream(ChatRequest $request, callable $onDelta): ChatResponse
    {
        $this->received[] = $request;

        $response = $this->next($request);

        // Emit in word-sized chunks so coalescing behaviour is exercised
        // realistically rather than arriving as one delta.
        foreach ($this->chunk($response->content) as $chunk) {
            $onDelta(StreamDelta::text($chunk));

            if ($this->chunkDelayMicroseconds > 0) {
                usleep($this->chunkDelayMicroseconds);
            }
        }

        foreach ($response->toolCalls as $call) {
            $onDelta(StreamDelta::toolCall($call));
        }

        $onDelta(StreamDelta::usage($response->usage));
        $onDelta(StreamDelta::done());

        return $response;
    }

    private function next(ChatRequest $request): ChatResponse
    {
        if (! isset($this->script[$this->cursor])) {
            return $this->echoLastUserMessage($request);
        }

        $scripted = $this->script[$this->cursor++];

        if ($scripted instanceof \Throwable) {
            throw $scripted;
        }

        return $scripted;
    }

    private function echoLastUserMessage(ChatRequest $request): ChatResponse
    {
        $last = '';

        foreach (array_reverse($request->messages) as $message) {
            if ($message->role->value === 'user') {
                $last = $message->content;
                break;
            }
        }

        $content = $last === ''
            ? 'Hello from the Pandora fake provider.'
            : "You said: {$last}";

        return new ChatResponse(
            content: $content,
            finishReason: FinishReason::Stop,
            usage: $this->estimateUsage($content),
        );
    }

    /**
     * @return list<string>
     */
    private function chunk(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $parts = preg_split('/(?<=\s)/u', $content, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [$content] : $parts;
    }

    /**
     * A crude token estimate -- adequate for a fake, and never presented as
     * real accounting.
     */
    private function estimateUsage(string $content): UsageData
    {
        return new UsageData(
            inputTokens: 10,
            outputTokens: (int) ceil(mb_strlen($content) / 4),
        );
    }
}
