<?php

declare(strict_types=1);

namespace Pandora\Pandora\Contracts;

use Pandora\Pandora\Providers\Data\ProviderCapabilities;
use Pandora\Pandora\Providers\Data\ProviderHealth;

/**
 * Base provider contract.
 *
 * Implementations translate in BOTH directions between a vendor API and
 * Pandora's DTOs. No vendor SDK type may cross this boundary -- an
 * architecture test enforces it. See docs/architecture/provider-model.md.
 */
interface Provider
{
    public function key(): string;

    public function capabilities(): ProviderCapabilities;

    public function health(): ProviderHealth;
}
