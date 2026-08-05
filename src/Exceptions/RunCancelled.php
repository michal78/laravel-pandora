<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions;

final class RunCancelled extends PandoraException
{
    public static function byUser(string $runId): self
    {
        return new self("Run [{$runId}] was cancelled.");
    }

    public function userMessage(): string
    {
        return 'The run was cancelled.';
    }
}
