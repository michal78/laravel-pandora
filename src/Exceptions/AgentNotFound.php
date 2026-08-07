<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

final class AgentNotFound extends PandoraException
{
    public static function slug(string $slug): self
    {
        return new self("No agent registered with slug [{$slug}].");
    }

    public function userMessage(): string
    {
        return 'That agent is not available.';
    }
}
