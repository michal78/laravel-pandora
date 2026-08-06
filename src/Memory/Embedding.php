<?php

declare(strict_types=1);

namespace Pandora\Pandora\Memory;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Pandora\Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Pandora\Support\Concerns\PandoraModel;

/**
 * One vector, stored portably.
 *
 * The `vector` column is JSON on every engine. That is not the fast path and
 * is not meant to be: it is the path that exists in a default install with no
 * extension, so that swapping in pgvector later is a configuration change
 * rather than a re-embedding project.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $provider_key
 * @property string $model_key
 * @property int $dimensions
 * @property list<float> $vector
 * @property string $content_hash
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class Embedding extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'embeddings';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'owner_type', 'owner_id', 'provider_key', 'model_key',
        'dimensions', 'vector', 'content_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'vector' => 'array',
        ];
    }

    /**
     * The hash of the text this vector was produced from.
     *
     * Centralised so the writer and the cache check can never disagree about
     * whitespace, which is the kind of difference that quietly re-embeds an
     * entire corpus on every deploy.
     */
    public static function hash(string $content): string
    {
        return hash('sha256', trim($content));
    }
}
