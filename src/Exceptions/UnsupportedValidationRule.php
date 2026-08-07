<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

/**
 * A tool declared a validation rule the schema generator cannot express.
 *
 * Thrown at REGISTRATION, not at call time. A schema that silently omits a
 * constraint tells the model it may send something the tool will then reject,
 * which is worse than refusing to boot.
 */
final class UnsupportedValidationRule extends PandoraException
{
    public static function make(string $tool, string $field, string $rule): self
    {
        return new self(
            "Tool [{$tool}] declares rule [{$rule}] on field [{$field}], which cannot be expressed "
            .'as a JSON schema. Either remove it, or override schema() on the tool to supply one.',
        );
    }

    public function userMessage(): string
    {
        return 'This tool is misconfigured and cannot be used.';
    }
}
