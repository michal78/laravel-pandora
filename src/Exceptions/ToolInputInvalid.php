<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

/**
 * The model sent arguments that failed the tool's validation rules.
 *
 * This is an ordinary, expected event -- not a system failure. The run
 * continues; the model is told what was wrong so it can correct itself.
 */
final class ToolInputInvalid extends PandoraException
{
    /**
     * @param array<string, list<string>> $errors
     */
    private function __construct(
        string $message,
        public readonly string $tool,
        public readonly array $errors,
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    public static function make(string $tool, array $errors): self
    {
        $summary = implode('; ', array_map(
            static fn (string $field, array $messages): string => $field.': '.implode(' ', $messages),
            array_keys($errors),
            array_values($errors),
        ));

        return new self("Invalid arguments for tool [{$tool}]. {$summary}", $tool, $errors);
    }

    /**
     * What the model is shown, so it can retry with corrected arguments.
     */
    public function modelMessage(): string
    {
        return $this->getMessage();
    }

    public function userMessage(): string
    {
        return 'The agent called a tool with invalid arguments.';
    }
}
