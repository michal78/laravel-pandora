<?php

declare(strict_types=1);

namespace Pandora\Exceptions\Provider;

final class ProviderAuthenticationFailed extends ProviderException
{
    /** Failover may succeed -- a different provider has a different credential. */
    public function allowsFailover(): bool
    {
        return true;
    }

    public function userMessage(): string
    {
        return 'The AI provider rejected the configured credentials.';
    }
}
