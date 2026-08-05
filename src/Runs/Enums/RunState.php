<?php

declare(strict_types=1);

namespace Pandora\Pandora\Runs\Enums;

/**
 * The run lifecycle.
 *
 * The three `waiting_*` states and `paused` are the reason for Pandora's whole
 * execution architecture: in those states NO job is in flight, so a run can
 * wait days for a human without holding a worker, surviving deploys in the
 * meantime. See docs/adr/0001-durable-state-machine-execution.md.
 */
enum RunState: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Starting = 'starting';
    case Running = 'running';
    case WaitingForTool = 'waiting_for_tool';
    case WaitingForApproval = 'waiting_for_approval';
    case WaitingForUser = 'waiting_for_user';
    case Paused = 'paused';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Failed,
            self::Cancelled,
            self::TimedOut,
        ], true);
    }

    /** No job is in flight; the run costs nothing until something external happens. */
    public function isWaiting(): bool
    {
        return in_array($this, [
            self::WaitingForApproval,
            self::WaitingForUser,
            self::Paused,
        ], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /** States from which a continuation job may legitimately proceed. */
    public function isContinuable(): bool
    {
        return in_array($this, [
            self::Starting,
            self::Running,
            self::WaitingForTool,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Starting => 'Starting',
            self::Running => 'Running',
            self::WaitingForTool => 'Running tools',
            self::WaitingForApproval => 'Waiting for approval',
            self::WaitingForUser => 'Waiting for you',
            self::Paused => 'Paused',
            self::Cancelling => 'Cancelling',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::TimedOut => 'Timed out',
        };
    }

    /** Semantic colour token; mapped to classes by the UI, not here. */
    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed, self::TimedOut => 'danger',
            self::Cancelled, self::Paused => 'muted',
            self::WaitingForApproval, self::WaitingForUser => 'warning',
            default => 'info',
        };
    }
}
