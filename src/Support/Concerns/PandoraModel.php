<?php

declare(strict_types=1);

namespace Pandora\Pandora\Support\Concerns;

/**
 * Shared plumbing for Pandora's Eloquent models: ULID keys, the configured
 * connection, and the configured table prefix.
 */
trait PandoraModel
{
    use HasPandoraUlids;

    public function getConnectionName(): ?string
    {
        /** @var string|null $connection */
        $connection = config('pandora.database.connection');

        return $connection ?? parent::getConnectionName();
    }

    public function getTable(): string
    {
        /** @var string $prefix */
        $prefix = config('pandora.database.table_prefix', 'pandora_');

        return $prefix.$this->pandoraTable;
    }
}
