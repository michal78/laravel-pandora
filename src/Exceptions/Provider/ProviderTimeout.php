<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions\Provider;

final class ProviderTimeout extends ProviderException
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
        return 'The AI provider took too long to respond.';
    }
}
