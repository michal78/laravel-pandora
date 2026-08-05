<?php

declare(strict_types=1);

namespace Pandora\Pandora\Realtime;

use Illuminate\Contracts\Events\Dispatcher;
use Pandora\Pandora\Messages\Message;
use Pandora\Pandora\Realtime\Events\AssistantDeltaReceived;
use Pandora\Pandora\Realtime\Events\AssistantMessageCompleted;
use Pandora\Pandora\Realtime\Events\MessageCreated;
use Pandora\Pandora\Realtime\Events\RunStatusChanged;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;

/**
 * The single place runs are announced.
 *
 * Concentrating this here means the redaction and versioning rules are applied
 * once, and a new call site cannot quietly skip them.
 */
final class RunBroadcaster
{
    /** @var array<string, int> */
    private array $deltaSequences = [];

    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function stateChanged(Run $run, ?RunState $previous = null, ?string $safeError = null): void
    {
        $this->events->dispatch(RunStatusChanged::from($run, $previous, $safeError));
    }

    public function messageCreated(Message $message, ?string $correlationId = null): void
    {
        $this->events->dispatch(MessageCreated::from($message, $correlationId));
    }

    public function delta(Run $run, Message $message, string $delta): void
    {
        if ($delta === '' || $run->conversation_id === null) {
            return;
        }

        $messageId = (string) $message->getKey();
        $sequence = $this->deltaSequences[$messageId] = ($this->deltaSequences[$messageId] ?? 0) + 1;

        $this->events->dispatch(new AssistantDeltaReceived(
            conversationId: $run->conversation_id,
            messageId: $messageId,
            runId: (string) $run->getKey(),
            delta: $delta,
            sequence: $sequence,
            correlationId: $run->correlation_id,
        ));
    }

    public function messageCompleted(Run $run, Message $message, bool $failed = false): void
    {
        if ($run->conversation_id === null) {
            return;
        }

        unset($this->deltaSequences[(string) $message->getKey()]);

        $this->events->dispatch(new AssistantMessageCompleted(
            conversationId: $run->conversation_id,
            messageId: (string) $message->getKey(),
            runId: (string) $run->getKey(),
            failed: $failed,
            correlationId: $run->correlation_id,
        ));
    }
}
