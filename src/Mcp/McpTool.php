<?php

declare(strict_types=1);

namespace Pandora\Mcp;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Support\Concerns\PandoraModel;

/**
 * A tool a remote server says it has.
 *
 * Every column here except `namespaced_name` and the hashes is text a third
 * party wrote. The description in particular is not documentation — it is a
 * sentence that ends up in front of a model deciding what to do next, so it is
 * bounded on the way in, escaped on the way out, and never placed where an
 * instruction goes (ADR-0014).
 *
 * A row existing grants nothing. Approval is per agent, in
 * `pandora_mcp_tool_approvals`, and it is approval of a HASH rather than of a
 * name: the call path re-hashes and compares, so a server that rewrites this
 * description tomorrow has un-approved itself.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $server_id
 * @property string $remote_name
 * @property string $namespaced_name
 * @property string|null $description
 * @property array<string, mixed>|null $input_schema
 * @property string $schema_hash
 * @property CarbonInterface|null $schema_changed_at
 * @property string|null $previous_schema_hash
 * @property string|null $previous_description
 * @property bool $available
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class McpTool extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'mcp_tools';

    /** @var array<string, mixed> */
    protected $attributes = [
        'available' => true,
    ];

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'server_id', 'remote_name', 'namespaced_name', 'description',
        'input_schema', 'schema_hash', 'schema_changed_at', 'previous_schema_hash',
        'previous_description',
        'available', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'schema_changed_at' => 'datetime',
            'available' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<McpServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(McpServer::class, 'server_id');
    }

    /**
     * @return HasMany<McpToolApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(McpToolApproval::class, 'mcp_tool_id');
    }

    /**
     * The description, bounded, for anywhere it is about to be shown or sent.
     *
     * Bounded again at read time rather than trusted to have been bounded at
     * write time: the write path is not the only way a row arrives, and the
     * cost of asking twice is one `mb_substr`.
     */
    public function boundedDescription(): string
    {
        $limit = (int) config('pandora.mcp.client.max_description_length', 2000);

        return mb_substr((string) $this->description, 0, max(1, $limit));
    }
}
