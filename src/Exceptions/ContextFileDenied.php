<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

/**
 * A context file was refused.
 *
 * The messages here name the path, because an operator debugging their own
 * configuration needs to know which line is wrong. They never say whether the
 * file exists -- that distinction is what turns a refusal into a filesystem
 * oracle.
 */
final class ContextFileDenied extends PandoraException
{
    public static function outsideRoots(string $path): self
    {
        return new self(
            "Context file [{$path}] is not inside a configured root. ".
            'See pandora.context.files.roots.',
        );
    }

    public static function noRootsConfigured(string $path): self
    {
        return new self(
            "Context file [{$path}] was requested, but no roots are configured. ".
            'An empty allowlist permits nothing, deliberately.',
        );
    }

    public static function unreadable(string $path): self
    {
        return new self("Context file [{$path}] could not be opened.");
    }

    public function userMessage(): string
    {
        return 'A context file could not be read.';
    }
}
