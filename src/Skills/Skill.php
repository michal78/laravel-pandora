<?php

declare(strict_types=1);

namespace Pandora\Skills;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Pandora\Agents\Agent;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Support\Concerns\PandoraModel;

/**
 * A reusable body of instructions an agent can be given.
 *
 * Instructions, never code -- ADR-0008. There is deliberately no column here
 * that could hold something executable, and no loader that would run one if
 * there were. A skill installed from a manifest that could execute is
 * arbitrary code execution driven by a database row, which is the same reason
 * the parity matrix classes remote marketplace install as Unsupported.
 *
 * `required_tools` and `required_abilities` are declarations, not grants. A
 * skill saying it needs `send_notification` does not make that tool available:
 * the agent's own allowlist still decides, and a skill whose requirements are
 * unmet is shown as such rather than silently half-working.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $slug
 * @property string $version
 * @property string|null $author
 * @property string|null $description
 * @property string $instructions
 * @property array<string, mixed>|null $manifest
 * @property list<string>|null $trigger_hints
 * @property list<string>|null $required_tools
 * @property list<string>|null $required_abilities
 * @property string $source
 * @property string $validation_status
 * @property array<string, mixed>|null $validation_errors
 * @property bool $enabled
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class Skill extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'skills';

    /** @var array<string, mixed> */
    protected $attributes = [
        'version' => '1.0.0',
        'source' => 'local',
        'validation_status' => 'valid',
        'enabled' => true,
    ];

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'name', 'slug', 'version', 'author', 'description',
        'instructions', 'manifest', 'trigger_hints', 'required_tools',
        'required_abilities', 'source', 'validation_status', 'validation_errors',
        'enabled', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'trigger_hints' => 'array',
            'required_tools' => 'array',
            'required_abilities' => 'array',
            'validation_errors' => 'array',
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsToMany<Agent, $this> */
    public function agents(): BelongsToMany
    {
        /** @var string $prefix */
        $prefix = config('pandora.database.table_prefix', 'pandora_');

        return $this->belongsToMany(Agent::class, $prefix.'agent_skills', 'skill_id', 'agent_id')
            ->withPivot('enabled')
            ->withTimestamps();
    }

    /**
     * Tools this skill says it needs that the agent cannot actually call.
     *
     * Surfaced rather than resolved. Granting a tool because a skill asked for
     * it would make the skill the authority on what an agent may do, which is
     * exactly backwards.
     *
     * @return list<string>
     */
    public function unmetToolRequirements(Agent $agent): array
    {
        $required = $this->required_tools ?? [];

        if ($required === []) {
            return [];
        }

        /** @var array{allow?: list<string>} $policy */
        $policy = $agent->tool_policy ?? [];
        $allowed = $policy['allow'] ?? [];

        return array_values(array_diff($required, $allowed));
    }
}
