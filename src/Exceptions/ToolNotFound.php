<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

/**
 * The model asked for a tool that is not registered.
 *
 * Authorization layer 1. Expected rather than exceptional: a model will
 * hallucinate a tool name sooner or later, and the run should tell it so and
 * carry on, not fail.
 */
final class ToolNotFound extends PandoraException
{
    public static function named(string $reference): self
    {
        return new self("No tool is registered as [{$reference}].");
    }

    public function userMessage(): string
    {
        return 'The agent tried to use a tool that is not available.';
    }
}
