<?php

declare(strict_types=1);

namespace Pandora\Exceptions\Provider;

/**
 * The request exceeded the model's context window. Not retryable as-is, but a
 * larger-context model in the fallback chain may succeed.
 */
final class ContextOverflow extends ProviderException
{
    public function allowsFailover(): bool
    {
        return true;
    }

    public function userMessage(): string
    {
        return 'This conversation is too long for the selected model.';
    }
}
