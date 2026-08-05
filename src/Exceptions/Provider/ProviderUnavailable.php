<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions\Provider;

final class ProviderUnavailable extends ProviderException
{
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
        return 'The AI provider is temporarily unavailable. Please try again.';
    }
}
