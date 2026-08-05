<?php

declare(strict_types=1);

namespace Pandora\Pandora\Testing;

use Pandora\Pandora\Contracts\StreamingProvider;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Providers\Data\UsageData;

/**
 * Everything the shared contract suite needs to know about ONE vendor's wire
 * format.
 *
 * The suite asserts Pandora's behaviour; this interface supplies the vendor's
 * dialect. Splitting them is what lets one test file prove every adapter --
 * including an adapter shipped by somebody else's package, which can implement
 * this interface and run `ProviderContractTests::for()` in its own suite.
 *
 * Implementations describe a FAKE server. Nothing here may reach a network.
 *
 * @see ProviderContractTests
 */
interface ProviderFixtures
{
    /** The connection key the adapter under test was built with. */
    public function key(): string;

    /** A model name valid for this vendor, used in every request. */
    public function model(): string;

    /** An `Http::fake()` URL pattern matching this vendor's endpoints. */
    public function endpointPattern(): string;

    /** Build the adapter under test. Called inside an active HTTP fake. */
    public function make(): StreamingProvider;

    /** The header this vendor carries its credential in. */
    public function credentialHeader(): string;

    /** The API key the adapter is configured with, so the suite can hunt for it. */
    public function apiKey(): string;

    // ------------------------------------------------------------- responses

    /**
     * A successful plain completion.
     *
     * @return array<string, mixed>
     */
    public function completionResponse(
        string $text,
        int $inputTokens = 11,
        int $outputTokens = 7,
        int $cachedInputTokens = 0,
    ): array;

    /**
     * A response the vendor cut short at the output-token limit.
     *
     * @return array<string, mixed>
     */
    public function truncatedResponse(string $text): array;

    /**
     * A response requesting one or more tools.
     *
     * @param list<ToolCall> $calls
     * @return array<string, mixed>
     */
    public function toolCallResponse(array $calls): array;

    /**
     * The RAW streamed body, exactly as the vendor would write it to the wire.
     *
     * @param list<ToolCall> $toolCalls
     */
    public function streamResponse(string $text, array $toolCalls = [], ?UsageData $usage = null): string;

    /**
     * An error body in this vendor's shape.
     *
     * @return array<string, mixed>
     */
    public function errorResponse(string $message, ?string $code = null): array;

    /** A context-window message in this vendor's own words. */
    public function contextOverflowMessage(): string;

    /** A body that is not parseable -- a truncated response, typically. */
    public function malformedBody(): string;

    // ------------------------------------------------- reading what was sent

    /**
     * @param array<string, mixed> $body
     */
    public function sentModel(array $body): ?string;

    /**
     * The conversation as this vendor received it, normalised back to roles
     * and text so the suite can assert on it without knowing the dialect.
     *
     * @param array<string, mixed> $body
     * @return list<array{role: string, content: string}>
     */
    public function sentMessages(array $body): array;

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    public function sentToolNames(array $body): array;

    /**
     * The system prompt, wherever this vendor puts it.
     *
     * @param array<string, mixed> $body
     */
    public function sentSystemPrompt(array $body): ?string;

    /**
     * The ids of the tool results in the request, so the suite can prove a
     * result was replayed against the call that asked for it.
     *
     * @param array<string, mixed> $body
     * @return list<string>
     */
    public function sentToolResultIds(array $body): array;
}
