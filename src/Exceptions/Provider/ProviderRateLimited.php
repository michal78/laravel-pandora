<?php

declare(strict_types=1);

namespace Pandora\Exceptions\Provider;

final class ProviderRateLimited extends ProviderException
{
    public ?int $retryAfterSeconds = null;

    public function retryAfter(?int $seconds): self
    {
        $this->retryAfterSeconds = $seconds;

        return $this;
    }

    public function isRetryable(): bool
    {
        return true;
    }

    public function allowsFailover(): bool
    {
        return true;
    }

    public function userMessage(): string
    {
        return 'The AI provider is rate limiting requests. Please try again shortly.';
    }
}
