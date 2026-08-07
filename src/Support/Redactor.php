<?php

declare(strict_types=1);

namespace Pandora\Support;

/**
 * Removes sensitive values from arrays destined for logs, run traces,
 * broadcasts and API responses.
 *
 * Redaction happens where a payload is CONSTRUCTED, not where it is
 * serialised, so there is no code path that can emit an unredacted payload by
 * forgetting a call.
 */
final class Redactor
{
    /**
     * @param list<string> $sensitiveKeys
     */
    public function __construct(
        private readonly array $sensitiveKeys,
        private readonly string $placeholder = '[redacted]',
    ) {}

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $result[$key] = $this->placeholder;

                continue;
            }

            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }

    /**
     * Redact anything in free text that looks like a credential.
     *
     * This is a belt-and-braces pass for values we could not catch by key --
     * a bearer token echoed inside a tool result, for example. It is not a
     * substitute for keeping secrets out of payloads in the first place.
     */
    public function redactText(string $text): string
    {
        $patterns = [
            '/\bsk-[A-Za-z0-9_\-]{16,}\b/',                  // OpenAI-style keys
            '/\bsk-ant-[A-Za-z0-9_\-]{16,}\b/',              // Anthropic-style keys
            '/\bBearer\s+[A-Za-z0-9._\-]{16,}\b/i',          // bearer tokens
            '/\bghp_[A-Za-z0-9]{20,}\b/',                    // GitHub tokens
            '/\bey[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\b/', // JWTs
        ];

        return (string) preg_replace($patterns, $this->placeholder, $text);
    }

    private function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        foreach ($this->sensitiveKeys as $sensitive) {
            if (str_contains($normalized, strtolower($sensitive))) {
                return true;
            }
        }

        return false;
    }
}
