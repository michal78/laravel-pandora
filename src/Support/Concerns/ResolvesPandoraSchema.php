<?php

declare(strict_types=1);

namespace Pandora\Pandora\Support\Concerns;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Lets migrations honour the configured connection and table prefix without
 * every file repeating the lookup.
 */
trait ResolvesPandoraSchema
{
    protected function schema(): Builder
    {
        return Schema::connection($this->connection());
    }

    protected function connection(): ?string
    {
        /** @var string|null $connection */
        $connection = config('pandora.database.connection');

        return $connection;
    }

    protected function table(string $name): string
    {
        /** @var string $prefix */
        $prefix = config('pandora.database.table_prefix', 'pandora_');

        return $prefix.$name;
    }
}
