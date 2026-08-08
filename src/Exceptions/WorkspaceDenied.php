<?php

declare(strict_types=1);

namespace Pandora\Exceptions;

/**
 * A workspace operation was refused.
 *
 * The message names the relative path, which the caller already supplied, and
 * never the resolved absolute one. Telling an agent that
 * `../../etc/passwd` resolved to `/etc/passwd` confirms the file exists and
 * confirms where the root is -- two facts worth more to an attacker than the
 * refusal costs them.
 */
final class WorkspaceDenied extends PandoraException
{
    public readonly string $reason;

    private function __construct(string $message, string $reason)
    {
        parent::__construct($message);

        $this->reason = $reason;
    }

    public static function path(string $relative, string $reason): self
    {
        return new self(
            "Workspace path [{$relative}] was refused ({$reason}).",
            $reason,
        );
    }

    public static function rootMissing(string $root): self
    {
        return new self(
            "The workspace root [{$root}] does not exist or is not a directory.",
            'root_missing',
        );
    }

    public static function quotaExceeded(string $relative, int $requested, int $quota): self
    {
        return new self(
            "Writing [{$relative}] needs {$requested} more bytes than the workspace quota of {$quota} allows.",
            'quota_exceeded',
        );
    }

    /**
     * The store this workspace lives on could not be reached.
     *
     * A refusal rather than a fallback, and the distinction is the whole of
     * ADR-0013 decision 2: writing somewhere else instead would put the file
     * on exactly one container, where every other node reads past it and
     * nothing about the situation looks like an error.
     *
     * The underlying message is carried for the trace and is not in
     * `userMessage()` -- an endpoint, a bucket name and a signature error are
     * operator facts, and the agent only needs to know it cannot use files
     * right now.
     */
    public static function diskUnavailable(string $disk, string $detail = ''): self
    {
        return new self(
            "The workspace disk [{$disk}] could not be reached."
                .($detail === '' ? '' : " ({$detail})"),
            'disk_unavailable',
        );
    }

    public static function noWorkspace(): self
    {
        return new self(
            'This agent has no workspace, so it can reach no files at all.',
            'no_workspace',
        );
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            'quota_exceeded' => 'The workspace is full.',
            'disk_unavailable' => 'The workspace storage cannot be reached right now. Try again, or work without files.',
            'no_workspace' => 'This agent has no workspace.',
            'mime_not_allowed' => 'That kind of file is not allowed in this workspace.',
            default => 'That path is not available in this workspace.',
        };
    }
}
