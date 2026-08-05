<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers\Credentials;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Support\Concerns\PandoraModel;

/**
 * A stored provider credential.
 *
 * Deliberately NOT using the BelongsToTenant trait. A deployment-wide
 * credential has a null `tenant_id` and the global scope would hide it the
 * moment a tenant is resolved, which is exactly when a run needs it. The
 * resolver therefore scopes explicitly -- and narrowly -- and
 * `Security/CredentialIsolationTest` proves it.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $agent_id
 * @property string $provider_key
 * @property string|null $label
 * @property string $secret
 * @property string $fingerprint
 * @property int $version
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property string|null $created_by_type
 * @property string|null $created_by_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ProviderCredential extends Model
{
    use PandoraModel;

    protected string $pandoraTable = 'provider_credentials';

    /**
     * `secret` is absent on purpose: it is set through the manager, which
     * stamps the fingerprint at the same time. A fillable secret would let a
     * request body become a credential.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id', 'agent_id', 'provider_key', 'label', 'version',
        'expires_at', 'revoked_at', 'created_by_type', 'created_by_id', 'metadata',
    ];

    /**
     * Never in an array, never in JSON, never in a Livewire payload.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'version' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The narrowest scope wins, so agent beats tenant beats deployment.
     */
    public function source(): CredentialSource
    {
        if ($this->agent_id !== null) {
            return CredentialSource::Agent;
        }

        return $this->tenant_id !== null
            ? CredentialSource::Tenant
            : CredentialSource::Deployment;
    }

    public function isUsable(?\DateTimeInterface $at = null): bool
    {
        $at ??= Carbon::now();

        if ($this->revoked_at !== null && $this->revoked_at <= $at) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at > $at;
    }

    public function toCredential(): Credential
    {
        return new Credential(
            secret: $this->secret,
            providerKey: $this->provider_key,
            source: $this->source(),
            id: $this->id,
            version: $this->version,
        );
    }
}
