<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

use Pandora\Memory\Enums\MemoryScope;

/**
 * A memory was written with a scope that does not identify anyone.
 *
 * Thrown at write time rather than filtered at read time on purpose. A `user`
 * memory with no `scope_id` is a memory belonging to everybody, and the moment
 * it is in the table some future query will match it.
 */
final class InvalidMemoryScope extends PandoraException
{
    public static function missingScopeId(MemoryScope $scope): self
    {
        return new self(
            "Memory scope [{$scope->value}] requires a scope id; a memory scoped to ".
            'everyone is a leak, not a default.',
        );
    }

    public static function unexpectedScopeId(MemoryScope $scope): self
    {
        return new self(
            "Memory scope [{$scope->value}] takes no scope id, but one was given.",
        );
    }

    public static function tenantedGlobal(string $tenantId): self
    {
        return new self(
            "Global memory belongs to no tenant, but this one carries [{$tenantId}]. ".
            'A tenant-scoped memory wearing a global label is never retrievable.',
        );
    }

    public static function notWritableByAgent(MemoryScope $scope): self
    {
        return new self(
            "An agent may not write memory in scope [{$scope->value}].",
        );
    }

    public function userMessage(): string
    {
        return 'That memory could not be stored: its scope does not identify who it belongs to.';
    }
}
