<?php

declare(strict_types=1);

namespace Pandora\Providers\Credentials;

use Illuminate\Contracts\Config\Repository as Config;
use Pandora\Contracts\CredentialResolver;

/**
 * The default resolver: stored credentials first, configuration second.
 *
 * Order, first match wins:
 *
 *     per-agent -> per-tenant -> deployment-wide -> config -> environment
 *
 * The database half is one query. It returns every candidate row for the
 * provider that this context is allowed to see, and the ordering below picks
 * the winner -- narrowest scope, then newest version. Doing it in one query
 * rather than three keeps a model call to a single round trip.
 *
 * Cross-tenant safety is a WHERE clause, not a global scope: a deployment-wide
 * credential has a null tenant_id and a scope would hide it exactly when it is
 * needed. See Security/CredentialIsolationTest.
 */
final class DatabaseCredentialResolver implements CredentialResolver
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function resolve(string $providerKey, ResolutionContext $context): ?Credential
    {
        if ($this->config->get('pandora.providers.credentials.database', true) === true) {
            $stored = $this->fromDatabase($providerKey, $context);

            if ($stored !== null) {
                return $stored;
            }
        }

        return $this->fromConfiguration($providerKey);
    }

    private function fromDatabase(string $providerKey, ResolutionContext $context): ?Credential
    {
        $candidates = ProviderCredential::query()
            ->where('provider_key', $providerKey)
            // Narrower than the current context is not ours to use: a
            // credential belonging to a different agent, or to any agent when
            // we are not running one, must not be reachable.
            ->where(function ($query) use ($context): void {
                $query->whereNull('agent_id');

                if ($context->agentId !== null) {
                    $query->orWhere('agent_id', $context->agentId);
                }
            })
            ->where(function ($query) use ($context): void {
                $query->whereNull('tenant_id');

                if ($context->tenantId !== null) {
                    $query->orWhere('tenant_id', $context->tenantId);
                }
            })
            ->get();

        $winner = $candidates
            ->filter(static fn (ProviderCredential $credential): bool => $credential->isUsable())
            // Narrowest scope first -- agent, then tenant, then deployment --
            // and within a scope the newest version. A rotation is live the
            // moment its row exists; the superseded row stays usable only
            // until its grace window closes.
            ->sort(static fn (ProviderCredential $a, ProviderCredential $b): int => [
                self::scopeRank($a), -$a->version,
            ] <=> [
                self::scopeRank($b), -$b->version,
            ])
            ->first();

        return $winner?->toCredential();
    }

    private static function scopeRank(ProviderCredential $credential): int
    {
        return match ($credential->source()) {
            CredentialSource::Agent => 0,
            CredentialSource::Tenant => 1,
            default => 2,
        };
    }

    /**
     * The connection's own `api_key`.
     *
     * This IS the environment branch: `pandora.php` reads every provider key
     * from an env var, and config is the only place Laravel guarantees env()
     * still works once the config cache is warm. Reading the environment a
     * second time here would return null on exactly the deployments that
     * cache their config -- production ones.
     */
    private function fromConfiguration(string $providerKey): ?Credential
    {
        /** @var string|null $configured */
        $configured = $this->config->get("pandora.providers.connections.{$providerKey}.api_key");

        if (is_string($configured) && $configured !== '') {
            return new Credential($configured, $providerKey, CredentialSource::Config);
        }

        return null;
    }
}
