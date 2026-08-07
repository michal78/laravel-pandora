<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

final class InvalidConfiguration extends PandoraException
{
    public static function missingProvider(string $key): self
    {
        return new self("Provider [{$key}] is not configured. Add it to config/pandora.php under providers.connections.");
    }

    public static function unknownAdapter(string $adapter, string $provider): self
    {
        return new self("Provider [{$provider}] refers to unknown adapter [{$adapter}].");
    }

    public static function missingCredential(string $provider): self
    {
        return new self("Provider [{$provider}] has no API key configured. Set the relevant environment variable.");
    }

    /**
     * A configuration mistake that does not warrant its own factory.
     */
    public static function make(string $message): self
    {
        return new self($message);
    }

    public function userMessage(): string
    {
        return 'Pandora is not configured correctly. Please contact an administrator.';
    }
}
