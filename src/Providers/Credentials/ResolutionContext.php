<?php

declare(strict_types=1);

namespace Pandora\Providers\Credentials;

/**
 * Who a credential is being resolved for.
 *
 * Carries identifiers only. A resolution context is passed around freely --
 * into a resolver a host application may have written -- so it must never be
 * a place a secret could be.
 */
final readonly class ResolutionContext
{
    public function __construct(
        public ?string $tenantId = null,
        public ?string $agentId = null,
    ) {}

    public function withAgent(?string $agentId): self
    {
        return new self($this->tenantId, $agentId);
    }

    public function withTenant(?string $tenantId): self
    {
        return new self($tenantId, $this->agentId);
    }
}
