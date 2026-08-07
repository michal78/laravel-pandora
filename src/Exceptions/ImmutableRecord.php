<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

final class ImmutableRecord extends PandoraException
{
    public static function cannotUpdate(string $model, string $id): self
    {
        return new self("[{$model}] is append-only and cannot be updated (id {$id}).");
    }

    public static function cannotDelete(string $model, string $id): self
    {
        return new self("[{$model}] is append-only and can only be removed by the retention pruner (id {$id}).");
    }
}
