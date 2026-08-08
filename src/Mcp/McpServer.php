<?php

declare(strict_types=1);

namespace Pandora\Mcp;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Mcp\Enums\McpTransport;
use Pandora\Mcp\Enums\ServerHealth;
use Pandora\Support\Concerns\PandoraModel;

/**
 * A remote MCP server: somewhere tools live that we did not write.
 *
 * The row holds where the server is and how to reach it, and deliberately
 * holds no secret — the credential lives in `pandora_provider_credentials`,
 * encrypted, resolved by the Phase 3 resolver (ADR-0014). Nothing in the
 * control center or the API ever renders one.
 *
 * `namespace` is operator configuration and is the reason remote tools can be
 * named at all. A server's own idea of its name is attacker-controlled input
 * being used as an identity, so it is never read from the wire.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $namespace
 * @property McpTransport $transport
 * @property string|null $endpoint
 * @property string|null $command
 * @property list<string>|null $command_arguments
 * @property string|null $credential_key
 * @property ServerHealth $health
 * @property string|null $health_message
 * @property CarbonInterface|null $last_probed_at
 * @property CarbonInterface|null $last_discovered_at
 * @property int $timeout_seconds
 * @property bool $enabled
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class McpServer extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'mcp_servers';

    /** @var array<string, mixed> */
    protected $attributes = [
        'transport' => 'http',
        'health' => 'unknown',
        'timeout_seconds' => 30,
        'enabled' => true,
    ];

    /**
     * No credential field of any kind, and that is enforced by a test.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id', 'name', 'slug', 'description', 'namespace', 'transport', 'endpoint',
        'command', 'command_arguments', 'credential_key', 'health', 'health_message',
        'last_probed_at', 'last_discovered_at', 'timeout_seconds', 'enabled', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transport' => McpTransport::class,
            'health' => ServerHealth::class,
            'command_arguments' => 'array',
            'last_probed_at' => 'datetime',
            'last_discovered_at' => 'datetime',
            'timeout_seconds' => 'integer',
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<McpTool, $this>
     */
    public function tools(): HasMany
    {
        return $this->hasMany(McpTool::class, 'server_id');
    }

    /**
     * Usable right now: enabled, and answering when last asked.
     *
     * An unhealthy server's tools are unavailable rather than slow — the same
     * rule Phase 3 applies to a degraded provider, and right for the same
     * reason. A run that waits on a server known to be down has converted a
     * clear failure into a timeout.
     */
    public function isUsable(): bool
    {
        return $this->enabled && $this->health !== ServerHealth::Unhealthy;
    }
}
