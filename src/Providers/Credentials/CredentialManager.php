<?php

declare(strict_types=1);

namespace Pandora\Providers\Credentials;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Pandora\Audit\AuditLogger;
use Pandora\Contracts\CredentialResolver;
use Pandora\Core\Actor\ActorManager;
use Pandora\Core\Tenancy\TenantManager;

/**
 * The application-facing half of credential handling: resolution against the
 * ambient context, and the write operations an operator performs.
 *
 * Adapters hold this, not a secret. They ask for a credential inside the
 * method that builds the HTTP request and drop it when that method returns,
 * which is what makes "a secret is never on a job payload" true by
 * construction rather than by discipline.
 */
final class CredentialManager
{
    private ?string $agentId = null;

    public function __construct(
        private readonly CredentialResolver $resolver,
        private readonly TenantManager $tenants,
        private readonly ActorManager $actors,
        private readonly AuditLogger $audit,
        private readonly Config $config,
    ) {}

    /**
     * Run a callback with an agent in scope, so per-agent credentials resolve.
     *
     * @template TReturn
     *
     * @param \Closure(): TReturn $callback
     * @return TReturn
     */
    public function forAgent(?string $agentId, \Closure $callback): mixed
    {
        $previous = $this->agentId;
        $this->agentId = $agentId;

        try {
            return $callback();
        } finally {
            $this->agentId = $previous;
        }
    }

    public function context(): ResolutionContext
    {
        return new ResolutionContext(
            tenantId: $this->tenants->currentId(),
            agentId: $this->agentId,
        );
    }

    public function resolve(string $providerKey): ?Credential
    {
        return $this->resolver->resolve($providerKey, $this->context());
    }

    /**
     * Store a new credential for a scope that has none.
     */
    public function issue(
        string $providerKey,
        string $secret,
        ?string $tenantId = null,
        ?string $agentId = null,
        ?string $label = null,
    ): ProviderCredential {
        $credential = $this->write($providerKey, $secret, $tenantId, $agentId, $label, version: 1);

        $this->audit->record(
            action: 'credential.created',
            targetType: ProviderCredential::class,
            targetId: $credential->id,
            metadata: $this->describe($credential),
        );

        return $credential;
    }

    /**
     * Replace the credential for a scope, leaving the old one valid for the
     * configured grace window.
     *
     * The window is the whole point. Revoking in the same instant would fail
     * every request already in flight and every worker holding a resolved
     * value, turning a routine rotation into an incident.
     */
    public function rotate(
        string $providerKey,
        string $secret,
        ?string $tenantId = null,
        ?string $agentId = null,
        ?string $label = null,
    ): ProviderCredential {
        $superseded = $this->scopeQuery($providerKey, $tenantId, $agentId)
            ->whereNull('revoked_at')
            ->get();

        $graceMinutes = (int) $this->config->get('pandora.providers.credentials.rotation_grace_minutes', 60);
        $expiry = Carbon::now()->addMinutes($graceMinutes);

        $highestVersion = 0;

        foreach ($superseded as $old) {
            $highestVersion = max($highestVersion, $old->version);

            // An already-expiring row keeps its earlier expiry: a second
            // rotation must not extend the life of the first key.
            if ($old->expires_at === null || $old->expires_at->greaterThan($expiry)) {
                $old->expires_at = $expiry;
                $old->save();
            }
        }

        $credential = $this->write(
            $providerKey, $secret, $tenantId, $agentId, $label,
            version: $highestVersion + 1,
        );

        $this->audit->record(
            action: 'credential.rotated',
            targetType: ProviderCredential::class,
            targetId: $credential->id,
            metadata: $this->describe($credential) + [
                'superseded' => $superseded->pluck('id')->all(),
                'grace_until' => $expiry->toAtomString(),
            ],
        );

        return $credential;
    }

    /**
     * Revoke immediately, with no grace window. For a leaked key, where the
     * failing requests are the point.
     */
    public function revoke(ProviderCredential $credential): void
    {
        $credential->revoked_at = Carbon::now();
        $credential->save();

        $this->audit->record(
            action: 'credential.revoked',
            targetType: ProviderCredential::class,
            targetId: $credential->id,
            severity: 'warning',
            metadata: $this->describe($credential),
        );
    }

    /**
     * Every stored credential visible to the current tenant, for the UI.
     * Values are not loaded into anything the UI can reach -- `secret` is
     * hidden on the model and nothing here undoes that.
     *
     * @return Collection<int, ProviderCredential>
     */
    public function stored(?string $providerKey = null): Collection
    {
        $tenantId = $this->tenants->currentId();

        return ProviderCredential::query()
            ->when($providerKey !== null, static fn ($query) => $query->where('provider_key', $providerKey))
            ->when($tenantId !== null, static fn ($query) => $query->where(function ($inner) use ($tenantId): void {
                $inner->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            }))
            ->orderBy('provider_key')
            ->orderByDesc('version')
            ->get();
    }

    private function write(
        string $providerKey,
        string $secret,
        ?string $tenantId,
        ?string $agentId,
        ?string $label,
        int $version,
    ): ProviderCredential {
        $actor = $this->actors->current();

        $credential = new ProviderCredential;
        $credential->fill([
            'tenant_id' => $tenantId ?? $this->tenants->currentId(),
            'agent_id' => $agentId,
            'provider_key' => $providerKey,
            'label' => $label,
            'version' => $version,
            'created_by_type' => $actor?->type,
            'created_by_id' => $actor?->id,
        ]);

        // Secret and fingerprint are set together, outside fill(), so there is
        // no path by which request input becomes a credential.
        $credential->secret = $secret;
        $credential->fingerprint = Credential::fingerprintOf($secret);
        $credential->save();

        return $credential;
    }

    /**
     * @return Builder<ProviderCredential>
     */
    private function scopeQuery(string $providerKey, ?string $tenantId, ?string $agentId): Builder
    {
        $tenantId ??= $this->tenants->currentId();

        return ProviderCredential::query()
            ->where('provider_key', $providerKey)
            ->when($tenantId === null,
                static fn ($query) => $query->whereNull('tenant_id'),
                static fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->when($agentId === null,
                static fn ($query) => $query->whereNull('agent_id'),
                static fn ($query) => $query->where('agent_id', $agentId),
            );
    }

    /**
     * What an audit entry may say about a credential: everything except it.
     *
     * @return array<string, mixed>
     */
    private function describe(ProviderCredential $credential): array
    {
        return [
            'provider_key' => $credential->provider_key,
            'scope' => $credential->source()->value,
            'version' => $credential->version,
            'fingerprint' => $credential->fingerprint,
            'tenant_id' => $credential->tenant_id,
            'agent_id' => $credential->agent_id,
        ];
    }
}
