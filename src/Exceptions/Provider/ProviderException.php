<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions\Provider;

use Pandora\Pandora\Exceptions\PandoraException;

/**
 * Base for provider failures.
 *
 * Adapters MUST classify every failure into one of the subclasses. The
 * execution loop routes on `isRetryable()` and `allowsFailover()`, so an
 * unclassified error would either be retried forever or fail a run that a
 * fallback model could have completed.
 */
abstract class ProviderException extends PandoraException
{
    public function __construct(
        string $message,
        public readonly string $provider = 'unknown',
        public readonly ?string $model = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether the router should try the next model in the fallback chain.
     */
    public function allowsFailover(): bool
    {
        return false;
    }

    public function userMessage(): string
    {
        return 'The AI provider could not complete this request.';
    }
}
