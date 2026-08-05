<?php

declare(strict_types=1);

namespace Pandora\Pandora\Messages;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Pandora\Messages\Enums\MessageRole;
use Pandora\Pandora\Messages\Enums\MessageType;
use Pandora\Pandora\Messages\Enums\StreamingState;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Support\Concerns\PandoraModel;

/**
 * A persisted item in a conversation.
 *
 * Distinct from a run step: messages are the CONVERSATION, steps are the
 * TRACE. A user sees messages; an administrator debugging a run reads steps.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $conversation_id
 * @property string|null $session_id
 * @property string|null $run_id
 * @property MessageRole $role
 * @property MessageType $type
 * @property int $sequence
 * @property string|null $content
 * @property string $content_format
 * @property array<string, mixed>|null $structured
 * @property StreamingState $streaming_state
 * @property array<string, mixed>|null $attachments
 * @property string|null $tool_call_id
 * @property array<string, mixed>|null $usage
 * @property string|null $author_type
 * @property string|null $author_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Message extends Model
{
    use BelongsToTenant;
    use PandoraModel;

    protected string $pandoraTable = 'messages';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'conversation_id', 'session_id', 'run_id',
        'role', 'type', 'sequence', 'content', 'content_format',
        'structured', 'attachments', 'tool_call_id', 'usage',
        'streaming_state', 'author_type', 'author_id', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'type' => MessageType::class,
            'streaming_state' => StreamingState::class,
            'structured' => 'array',
            'attachments' => 'array',
            'usage' => 'array',
            'metadata' => 'array',
            'sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /** @param Builder<self> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNot('role', MessageRole::System->value);
    }

    public function isStreaming(): bool
    {
        return $this->streaming_state === StreamingState::Streaming;
    }

    /**
     * The tool calls an assistant message requested.
     *
     * Stored under `structured.tool_calls` rather than in their own table:
     * they are part of the message, they are always read with it, and a
     * separate table would buy a join and nothing else.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        /** @var list<array{id?: string, name?: string, arguments?: array<string, mixed>}> $calls */
        $calls = $this->structured['tool_calls'] ?? [];

        return array_map(static fn (array $call): ToolCall => new ToolCall(
            id: (string) ($call['id'] ?? ''),
            name: (string) ($call['name'] ?? ''),
            arguments: $call['arguments'] ?? [],
        ), $calls);
    }

    public function requestsTools(): bool
    {
        return ($this->structured['tool_calls'] ?? []) !== [];
    }
}
