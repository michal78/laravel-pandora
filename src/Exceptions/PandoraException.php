<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

use RuntimeException;

/**
 * Base of the Pandora exception hierarchy.
 *
 * Every exception carries two messages: a safe one shown to users, and the
 * full one retained for authorized administrators. Broad `catch (Throwable)`
 * is forbidden in this codebase precisely so that classification stays
 * meaningful.
 */
abstract class PandoraException extends RuntimeException
{
    /**
     * Safe to show to an end user. Never contains internal detail.
     */
    public function userMessage(): string
    {
        return 'Something went wrong while running the agent.';
    }

    /**
     * Whether retrying the same operation could plausibly succeed.
     */
    public function isRetryable(): bool
    {
        return false;
    }

    /**
     * A stable machine-readable code for logs, the API and the UI.
     */
    public function errorCode(): string
    {
        return 'pandora.'.str(class_basename(static::class))->snake()->toString();
    }
}
