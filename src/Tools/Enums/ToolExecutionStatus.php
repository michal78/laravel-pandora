<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools\Enums;

/**
 * The lifecycle of one tool call.
 *
 * `Denied` is terminal and ordinary: a refused call is information the model
 * acts on, not a failure of the run.
 */
enum ToolExecutionStatus: string
{
    case Pending = 'pending';
    case AwaitingApproval = 'awaiting_approval';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Denied = 'denied';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    /**
     * Whether this call still owes the run an answer. The run waits until no
     * call does.
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Failed,
            self::Denied,
            self::Skipped,
            self::Cancelled,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::AwaitingApproval => 'Waiting for approval',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Denied => 'Denied',
            self::Skipped => 'Skipped',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Semantic colour token; mapped to classes by the UI, not here. */
    public function tone(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Denied, self::Cancelled => 'warning',
            self::Skipped => 'muted',
            default => 'info',
        };
    }
}
