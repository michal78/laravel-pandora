<?php

declare(strict_types=1);

namespace Pandora\Pandora\Agents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Support\Concerns\PandoraModel;

/**
 * A configured agent identity: instructions, model preferences, limits and
 * policies. A definition, never a running process.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $enabled
 * @property string|null $definition_class
 * @property string|null $system_instructions
 * @property string|null $role_instructions
 * @property string|null $default_provider
 * @property string|null $default_model
 * @property array<int, string> $fallback_models
 * @property array<string, mixed> $provider_options
 * @property int $max_iterations
 * @property int $max_tool_calls
 * @property int $max_duration_seconds
 * @property int $context_budget_tokens
 * @property AutonomyLevel $autonomy_level
 * @property array<string, mixed> $metadata
 * @property string|null $avatar_path
 * @property int|null $token_budget
 * @property int|null $cost_budget_minor
 * @property string $currency
 * @property array<string, mixed>|null $memory_policy
 * @property string|null $workspace_id
 * @property array<string, mixed>|null $tool_policy
 * @property array<string, mixed>|null $approval_policy
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Agent extends Model
{
    use BelongsToTenant;
    use PandoraModel;
    use SoftDeletes;

    protected string $pandoraTable = 'agents';

    /**
     * Explicit fillable -- never `$guarded = []`. An agent row controls which
     * tools an LLM may reach, so mass-assignment protection is load-bearing.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id', 'name', 'slug', 'description', 'avatar_path', 'enabled',
        'definition_class', 'system_instructions', 'role_instructions',
        'default_provider', 'default_model', 'fallback_models', 'provider_options',
        'max_iterations', 'max_tool_calls', 'max_duration_seconds',
        'context_budget_tokens', 'token_budget', 'cost_budget_minor', 'currency',
        'autonomy_level', 'memory_policy', 'tool_policy', 'approval_policy', 'workspace_id', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'fallback_models' => 'array',
            'provider_options' => 'array',
            'memory_policy' => 'array',
            'tool_policy' => 'array',
            'approval_policy' => 'array',
            'metadata' => 'array',
            'autonomy_level' => AutonomyLevel::class,
            'max_iterations' => 'integer',
            'max_tool_calls' => 'integer',
            'max_duration_seconds' => 'integer',
            'context_budget_tokens' => 'integer',
            'token_budget' => 'integer',
            'cost_budget_minor' => 'integer',
        ];
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'agent_id');
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class, 'agent_id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The instructions sent to the model, framework part first.
     *
     * Kept separate in storage so the persona can be edited by an operator
     * without touching the safety boundary, and so `pandora.prompts.view`
     * can gate them independently.
     */
    public function composedInstructions(): string
    {
        return trim(implode("\n\n", array_filter([
            $this->system_instructions,
            $this->role_instructions,
        ])));
    }

    public function isClassDefined(): bool
    {
        return $this->definition_class !== null;
    }

    /**
     * Tool references this agent may request — authorization layer 2.
     *
     * An empty allowlist means NO tools, never all of them. An agent that can
     * reach a tool must have been given it on purpose.
     *
     * @return list<string>
     */
    public function allowedTools(): array
    {
        /** @var list<string> $allowed */
        $allowed = $this->tool_policy['allow'] ?? [];

        return $allowed;
    }

    /**
     * Tool references this agent may never request. Denial beats the
     * allowlist, so a whole group can be granted with one member carved out.
     *
     * @return list<string>
     */
    public function deniedTools(): array
    {
        /** @var list<string> $denied */
        $denied = $this->tool_policy['deny'] ?? [];

        return $denied;
    }
}
