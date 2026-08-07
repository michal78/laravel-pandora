<?php

declare(strict_types=1);

namespace Pandora\Contracts;

use Pandora\Exceptions\Provider\ProviderException;
use Pandora\Providers\Catalog\ModelDescriptor;

/**
 * A provider that can list the models it offers.
 *
 * Optional: a provider without a models endpoint is seeded from configuration
 * instead, and both routes end in the same catalog table.
 */
interface ModelCatalogProvider
{
    /**
     * @return list<ModelDescriptor>
     *
     * @throws ProviderException
     */
    public function models(): array;
}
