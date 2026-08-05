<?php

declare(strict_types=1);

namespace Pandora\Pandora\Contracts;

use Pandora\Pandora\Providers\Credentials\Credential;
use Pandora\Pandora\Providers\Credentials\ResolutionContext;

/**
 * Resolves the credential an adapter should present for a provider.
 *
 * A host application binds its own implementation to read from a secrets
 * manager -- Vault, AWS Secrets Manager, an internal service -- without
 * touching an adapter. Implementations MUST resolve lazily and MUST NOT
 * cache a secret anywhere it outlives the call.
 *
 * @see docs/architecture/provider-model.md section 7
 */
interface CredentialResolver
{
    /**
     * Return null when this deployment has no credential for the provider.
     * That is a normal answer -- a local Ollama needs none.
     */
    public function resolve(string $providerKey, ResolutionContext $context): ?Credential;
}
