<?php

declare(strict_types=1);

namespace Pandora\Pandora\Realtime\Events;

use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Runs\Run;

/**
 * Covers RunQueued, RunStarted, RunCompleted, RunFailed and RunCancelled:
 * they are the same fact -- a state transition -- and one versioned event with
 * a state field is easier for a client to handle correctly than five events
 * that must be kept mutually consistent.
 */
final class RunStatusChanged extends PandoraBroadcastEvent
{
    public function __construct(
        public readonly string $runId,
        public readonly ?string $conversationId,
        public readonly ?string $tenantId,
        public readonly RunState $state,
        public readonly ?RunState $previousState,
        public readonly ?string $correlationId = null,
        public readonly ?string $safeErrorMessage = null,
    ) {}

    public static function from(Run $run, ?RunState $previous = null, ?string $safeError = null): self
    {
        return new self(
            runId: (string) $run->getKey(),
            conversationId: $run->conversation_id,
            tenantId: $run->tenant_id,
            state: $run->state,
            previousState: $previous,
            correlationId: $run->correlation_id,
            safeErrorMessage: $safeError,
        );
    }

    public function eventName(): string
    {
        return 'pandora.run.status_changed';
    }

    public function broadcastOn(): array
    {
        $channels = [self::runChannel($this->runId)];

        if ($this->conversationId !== null) {
            $channels[] = self::conversationChannel($this->conversationId);
        }

        if ($this->tenantId !== null) {
            $channels[] = self::tenantChannel($this->tenantId);
        }

        return $channels;
    }

    protected function correlationId(): ?string
    {
        return $this->correlationId;
    }

    protected function payload(): array
    {
        return [
            'run_id' => $this->runId,
            'conversation_id' => $this->conversationId,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'tone' => $this->state->tone(),
            'previous_state' => $this->previousState?->value,
            'is_terminal' => $this->state->isTerminal(),
            // Safe message only. Internal exception detail never reaches a
            // broadcast; it stays on the run row for authorized administrators.
            'error' => $this->safeErrorMessage,
        ];
    }
}
