<?php

declare(strict_types=1);

namespace Pandora\Pandora\Workspaces;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Pandora\Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Pandora\Support\Concerns\PandoraModel;

/**
 * A bounded piece of filesystem an agent may use.
 *
 * The row is configuration and nothing an agent says reaches it. `disk` and
 * `root_path` in particular are operator-set, because the root is the thing
 * every containment check is measured against -- a root an agent could
 * influence is not a boundary, it is a suggestion.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $disk
 * @property string $root_path
 * @property int|null $quota_bytes
 * @property int $used_bytes
 * @property list<string>|null $allowed_mime_types
 * @property bool $enabled
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class Workspace extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'workspaces';

    /** @var array<string, mixed> */
    protected $attributes = [
        'used_bytes' => 0,
        'enabled' => true,
    ];

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'name', 'slug', 'description', 'disk', 'root_path',
        'quota_bytes', 'used_bytes', 'allowed_mime_types', 'enabled', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quota_bytes' => 'integer',
            'used_bytes' => 'integer',
            'allowed_mime_types' => 'array',
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function hasQuota(): bool
    {
        return $this->quota_bytes !== null;
    }

    /**
     * Bytes still available, or null when unlimited.
     */
    public function remainingBytes(): ?int
    {
        return $this->quota_bytes === null
            ? null
            : max(0, $this->quota_bytes - $this->used_bytes);
    }

    /**
     * Whether a MIME type may be stored here.
     *
     * An empty allowlist permits everything, which is the opposite of the
     * rule `ContextFiles` uses for roots -- and deliberately so. A root list
     * describes where files may come FROM and must fail closed; a MIME list
     * describes an optional narrowing of what may be put in a workspace that
     * is already bounded, and an operator who set none has not implicitly
     * banned everything.
     */
    public function allowsMimeType(string $mimeType): bool
    {
        $allowed = $this->allowed_mime_types ?? [];

        if ($allowed === []) {
            return true;
        }

        foreach ($allowed as $pattern) {
            if ($pattern === $mimeType) {
                return true;
            }

            // `image/*` style wildcards, so an operator does not have to
            // enumerate every image format that exists.
            if (str_ends_with($pattern, '/*')
                && str_starts_with($mimeType, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
