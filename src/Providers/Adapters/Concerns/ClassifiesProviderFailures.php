<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Adapters\Concerns;

use Illuminate\Http\Client\Response;
use Pandora\Pandora\Exceptions\Provider\ContextOverflow;
use Pandora\Pandora\Exceptions\Provider\ProviderAuthenticationFailed;
use Pandora\Pandora\Exceptions\Provider\ProviderQuotaExhausted;
use Pandora\Pandora\Exceptions\Provider\ProviderRateLimited;
use Pandora\Pandora\Exceptions\Provider\ProviderRejectedRequest;
use Pandora\Pandora\Exceptions\Provider\ProviderTimeout;
use Pandora\Pandora\Exceptions\Provider\ProviderUnavailable;

/**
 * Turning an HTTP failure into a Pandora exception.
 *
 * Shared because the DECISION is the same everywhere -- retry, fail over, or
 * stop -- even though the bodies carrying it are not. Only message extraction
 * differs per vendor, and that is the one method an adapter implements.
 *
 * The prose lists below are the unglamorous heart of it: providers disagree
 * about status codes for the two cases where getting it wrong costs real
 * money or real time, so both are matched against the raw body as well as the
 * parsed message.
 */
trait ClassifiesProviderFailures
{
    abstract protected function providerKey(): string;

    /**
     * Pull the human-readable error out of this vendor's error body.
     */
    abstract protected function extractErrorMessage(string $body): ?string;

    protected function classifyFailure(Response $response, ?string $model): \Throwable
    {
        $status = $response->status();
        $body = (string) $response->body();
        $message = $this->extractErrorMessage($body) ?? "HTTP {$status}";
        $key = $this->providerKey();

        // Checked before the status, because vendors disagree about which
        // status an exhausted balance gets: OpenAI sends 429, Anthropic 400.
        // Retrying either is pointless -- a human has to add credit.
        if ($this->looksLikeExhaustedQuota($body, $message)) {
            return new ProviderQuotaExhausted($message, $key, $model);
        }

        if ($status === 401 || $status === 403) {
            return new ProviderAuthenticationFailed($message, $key, $model);
        }

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After');

            return (new ProviderRateLimited($message, $key, $model))
                ->retryAfter(is_numeric($retryAfter) ? (int) $retryAfter : null);
        }

        if ($status === 408 || $status === 504) {
            return new ProviderTimeout($message, $key, $model);
        }

        if ($status >= 500) {
            return new ProviderUnavailable($message, $key, $model);
        }

        // Context-window overflow arrives as a 400 nearly everywhere, but a
        // larger-context fallback model may succeed -- so it needs its own
        // class rather than being lost among ordinary rejections.
        if ($this->looksLikeContextOverflow($message)) {
            return new ContextOverflow($message, $key, $model);
        }

        return new ProviderRejectedRequest($message, $key, $model);
    }

    /**
     * Distinguish an exhausted balance from a genuine rate limit.
     *
     * The error `type`/`code` is the reliable signal where a server sends one;
     * the prose is a fallback for the many servers that do not. Both are
     * matched against the raw body, so a server reporting only the code is
     * still classified correctly.
     */
    protected function looksLikeExhaustedQuota(string $body, string $message): bool
    {
        return $this->matches($body.' '.$message, [
            'insufficient_quota',
            'insufficient_user_quota',
            'exceeded your current quota',
            'no credits remaining',
            'credit balance is too low',
            'billing_hard_limit_reached',
            'quota exceeded',
        ]);
    }

    protected function looksLikeContextOverflow(string $message): bool
    {
        return $this->matches($message, [
            'context length',
            'context_length',
            'maximum context',
            'context window',
            'too many tokens',
            'reduce the length',
            // Anthropic's phrasing.
            'prompt is too long',
            // Gemini's.
            'input token count',
            'exceeds the maximum number of tokens',
        ]);
    }

    /**
     * @param list<string> $needles
     */
    private function matches(string $haystack, array $needles): bool
    {
        $haystack = mb_strtolower($haystack);

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
