<?php

declare(strict_types=1);

namespace Pandora\Mcp;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Support\Concerns\PandoraModel;

/**
 * One agent may call one remote tool, as it read at the moment somebody said so.
 *
 * Per agent and per tool, never per server (ADR-0014). "Trust this server" is
 * a blanket that keeps covering tools added after it was issued: a server with
 * three approved tools that adds a fourth tomorrow has granted itself a
 * capability. And two agents on one server are two different blast radii — the
 * support agent and the deployment agent do not both get `restart_service`
 * because they share a registry.
 *
 * `approved_schema_hash` is what makes this an approval of a THING rather than
 * of a name. The call path re-hashes the tool and compares; a disagreement
 * clears the approval and fails closed.
 *
 * A revoked row is kept rather than deleted, because "approved once and taken
 * away" and "never approved" are different facts and an operator reading an
 * audit trail needs to tell them apart.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $agent_id
 * @property string $mcp_tool_id
 * @property string $approved_schema_hash
 * @property CarbonInterface $approved_at
 * @property string|null $approved_by_type
 * @property string|null $approved_by_id
 * @property CarbonInterface|null $revoked_at
 * @property string|null $revoked_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class McpToolApproval extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'mcp_tool_approvals';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'agent_id', 'mcp_tool_id', 'approved_schema_hash', 'approved_at',
        'approved_by_type', 'approved_by_id', 'revoked_at', 'revoked_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<McpTool, $this>
     */
    public function tool(): BelongsTo
    {
        return $this->belongsTo(McpTool::class, 'mcp_tool_id');
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Still an approval of the thing that was approved.
     *
     * Both halves matter: a revoked approval is not live, and a live approval
     * of a hash that no longer matches is an approval of an earlier version of
     * a tool somebody else has since edited.
     */
    public function covers(McpTool $tool): bool
    {
        return $this->isLive() && hash_equals($this->approved_schema_hash, $tool->schema_hash);
    }
}
