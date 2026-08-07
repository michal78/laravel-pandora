<?php

declare(strict_types=1);

namespace Pandora\Providers\Catalog;

use Pandora\Providers\Data\ProviderCapabilities;

/**
 * A model as a PROVIDER describes it.
 *
 * Deliberately carries no pricing. No vendor exposes prices through its API,
 * so anything a sync could put there would be invented -- and an invented
 * price is worse than an absent one, because it looks authoritative.
 */
final readonly class ModelDescriptor
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $providerKey,
        public string $modelKey,
        public ?string $displayName = null,
        public ?int $contextLimit = null,
        public ?int $maxOutputTokens = null,
        public ?ProviderCapabilities $capabilities = null,
        public ?\DateTimeImmutable $deprecatedAt = null,
        public array $metadata = [],
    ) {}
}
