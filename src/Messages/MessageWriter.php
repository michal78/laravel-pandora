<?php

declare(strict_types=1);

namespace Pandora\Pandora\Messages;

use Illuminate\Database\Connection;
use Pandora\Pandora\Conversations\Conversation;
use Pandora\Pandora\Messages\Enums\MessageRole;
use Pandora\Pandora\Messages\Enums\MessageType;
use Pandora\Pandora\Messages\Enums\StreamingState;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Runs\Run;

/**
 * Creates messages and applies streamed deltas.
 *
 * Deltas are buffered and flushed on a time or size threshold, so the database
 * write pattern stays sane while a mid-stream page reload still reconstructs
 * the partial message. Persisted state and broadcast state advance together --
 * see docs/adr/0003-streaming-inside-queued-jobs.md.
 */
final class MessageWriter
{
    private string $buffer = '';

    private ?float $lastFlushAt = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $flushIntervalMs = 80,
        private readonly int $flushChars = 256,
    ) {}

    public function userMessage(
        Conversation $conversation,
        string $sessionId,
        string $content,
        ?string $authorType = null,
        ?string $authorId = null,
    ): Message {
        return $this->create($conversation, [
            'session_id' => $sessionId,
            'role' => MessageRole::User->value,
            'type' => MessageType::Text->value,
            'content' => $content,
            'streaming_state' => StreamingState::Complete->value,
            'author_type' => $authorType,
            'author_id' => $authorId,
        ]);
    }

    /**
     * The empty assistant message a run streams into.
     *
     * Created up front, and immediately, so the UI has something to render and
     * a reload has something to find.
     */
    public function assistantPlaceholder(Conversation $conversation, Run $run): Message
    {
        return $this->create($conversation, [
            'session_id' => $run->session_id,
            'run_id' => $run->getKey(),
            'role' => MessageRole::Assistant->value,
            'type' => MessageType::Text->value,
            'content' => '',
            'streaming_state' => StreamingState::Streaming->value,
        ]);
    }

    public function errorMessage(Conversation $conversation, Run $run, string $safeMessage): Message
    {
        return $this->create($conversation, [
            'session_id' => $run->session_id,
            'run_id' => $run->getKey(),
            'role' => MessageRole::Assistant->value,
            'type' => MessageType::Error->value,
            'content' => $safeMessage,
            'streaming_state' => StreamingState::Failed->value,
        ]);
    }

    /**
     * Record the tool calls an assistant turn requested.
     *
     * Stored on the message that requested them so the pair travels together:
     * every provider that accepts a tool result also demands the request that
     * produced it, and reconstructing that from the execution rows later would
     * be guessing at an order the model chose.
     *
     * @param list<ToolCall> $toolCalls
     */
    public function recordToolCalls(Message $message, array $toolCalls): Message
    {
        $message->forceFill([
            'structured' => ['tool_calls' => array_map(
                static fn (ToolCall $call): array => $call->jsonSerialize(),
                $toolCalls,
            )] + ($message->structured ?? []),
        ])->save();

        return $message;
    }

    /**
     * A tool's result, as a message in the conversation.
     *
     * The content is UNTRUSTED -- a database row, a web page, a document --
     * and is labelled as a tool result precisely so the model can be told,
     * structurally, that it is data rather than instruction.
     */
    public function toolResult(
        Conversation $conversation,
        Run $run,
        string $toolCallId,
        string $content,
        string $toolName,
        bool $failed = false,
    ): Message {
        return $this->create($conversation, [
            'session_id' => $run->session_id,
            'run_id' => $run->getKey(),
            'role' => MessageRole::Tool->value,
            'type' => $failed ? MessageType::Error->value : MessageType::ToolResult->value,
            'content' => $content,
            'tool_call_id' => $toolCallId,
            'streaming_state' => StreamingState::Complete->value,
            'metadata' => ['tool' => $toolName],
        ]);
    }

    /**
     * A question the agent is waiting on an answer to.
     *
     * An assistant message rather than a tool result: the user is being
     * addressed, and it belongs in the conversation where they will see it.
     */
    public function question(Conversation $conversation, Run $run, string $question): Message
    {
        return $this->create($conversation, [
            'session_id' => $run->session_id,
            'run_id' => $run->getKey(),
            'role' => MessageRole::Assistant->value,
            'type' => MessageType::Text->value,
            'content' => $question,
            'streaming_state' => StreamingState::Complete->value,
            'metadata' => ['awaiting_answer' => true],
        ]);
    }

    /**
     * Buffer a delta, flushing when a threshold is reached.
     *
     * @return bool Whether a flush occurred (and therefore whether a broadcast is due).
     */
    public function appendDelta(Message $message, string $delta): bool
    {
        $this->buffer .= $delta;
        $this->lastFlushAt ??= microtime(true);

        $elapsedMs = (microtime(true) - $this->lastFlushAt) * 1000;

        if (mb_strlen($this->buffer) < $this->flushChars && $elapsedMs < $this->flushIntervalMs) {
            return false;
        }

        $this->flush($message);

        return true;
    }

    /**
     * Write whatever is buffered. Safe to call when the buffer is empty.
     */
    public function flush(Message $message): void
    {
        if ($this->buffer === '') {
            return;
        }

        $chunk = $this->buffer;
        $this->buffer = '';
        $this->lastFlushAt = microtime(true);

        $table = $message->getTable();

        // Append in SQL rather than read-modify-write: we never round-trip the
        // whole message to add a few characters, and a concurrent reader can
        // never observe a torn value.
        $this->connection->update(
            "update {$table} set content = {$this->appendExpression()}, updated_at = ? where id = ?",
            [$chunk, now(), $message->getKey()],
        );

        // Keep the in-memory instance consistent with the row.
        $message->content = ($message->content ?? '').$chunk;
    }

    public function complete(Message $message, ?string $finalContent = null): Message
    {
        $this->flush($message);

        $message->forceFill([
            'content' => $finalContent ?? $message->content,
            'streaming_state' => StreamingState::Complete->value,
        ])->save();

        return $message;
    }

    public function fail(Message $message, string $safeMessage): Message
    {
        $this->buffer = '';

        $message->forceFill([
            'content' => $safeMessage,
            'type' => MessageType::Error->value,
            'streaming_state' => StreamingState::Failed->value,
        ])->save();

        return $message;
    }

    public function pendingBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function create(Conversation $conversation, array $attributes): Message
    {
        return $this->connection->transaction(function () use ($conversation, $attributes): Message {
            /** @var Message $message */
            $message = Message::query()->create($attributes + [
                'conversation_id' => $conversation->getKey(),
                'tenant_id' => $conversation->tenant_id,
                'sequence' => $conversation->nextSequence(),
                'content_format' => 'markdown',
            ]);

            $conversation->forceFill(['last_activity_at' => now()])->save();

            return $message;
        });
    }

    /**
     * Portable "append to content" expression with a single bound parameter.
     *
     * SQLite and PostgreSQL concatenate with `||`; MySQL and MariaDB need
     * CONCAT(). Both forms coalesce, because `null || 'x'` is null.
     */
    private function appendExpression(): string
    {
        return match ($this->connection->getDriverName()) {
            'mysql', 'mariadb' => "CONCAT(COALESCE(content, ''), ?)",
            default => "COALESCE(content, '') || ?",
        };
    }
}
