<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions\Provider;

/**
 * The account has no credit or has exhausted its quota.
 *
 * OpenAI-compatible servers report this with the same 429 status as a genuine
 * rate limit, but the two need opposite handling: waiting helps a rate limit
 * and never helps an empty balance. Retrying here only spends the run's budget
 * on a request that cannot succeed until a human tops up the account.
 *
 * Failover is still allowed -- a different provider, or a different key, may
 * have credit.
 */
final class ProviderQuotaExhausted extends ProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }

    public function allowsFailover(): bool
    {
        return true;
    }

    public function userMessage(): string
    {
        return 'The AI provider rejected the request because the account has no remaining credit.';
    }
}
