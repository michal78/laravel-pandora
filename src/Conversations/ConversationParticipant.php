<?php

declare(strict_types=1);

namespace Pandora\Pandora\Conversations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Pandora\Support\Concerns\PandoraModel;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string $participant_type
 * @property string $participant_id
 * @property string $role
 * @property string|null $tenant_id
 * @property Carbon|null $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ConversationParticipant extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'conversation_participants';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'conversation_id', 'participant_type', 'participant_id',
        'role', 'joined_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
