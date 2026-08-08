<?php

declare(strict_types=1);

namespace Pandora\Workspaces;

use Illuminate\Database\Query\Builder;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Workspaces\Storage\StorageFactory;
use Pandora\Workspaces\Storage\WorkspaceStorage;

/**
 * Reads and writes inside a workspace, and nowhere else.
 *
 * Everything about *where the bytes go* lives below the seam, in a
 * `WorkspaceStorage` -- a filesystem or an object store, each with its own
 * containment logic because they are different problems (ADR-0013).
 *
 * What is left here is the part that is identical whichever disk answers: the
 * quota, the MIME allowlist, and the audit trail. None of those care about
 * symlinks or key prefixes, and keeping them above the seam is what stops the
 * second adapter from arriving with its own slightly different idea of when a
 * workspace is full.
 *
 * Paths from an agent are treated as hostile throughout. That is not paranoia
 * about the model: a tool argument is downstream of every document the agent
 * has read this run, and one of those documents is allowed to be a web page.
 */
final class WorkspaceFiles
{
    private readonly Denials $denials;

    private readonly WorkspaceStorage $storage;

    /**
     * @param WorkspaceStorage|null $storage the workspace's own disk when
     *                                       omitted, which is what every
     *                                       caller wants; passed explicitly
     *                                       only by tests that drive one
     *                                       adapter deliberately
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly AuditLogger $audit,
        ?WorkspaceStorage $storage = null,
    ) {
        $this->denials = new Denials($workspace, $audit);
        $this->storage = $storage ?? app(StorageFactory::class)->for($workspace);
    }

    /**
     * The adapter-native locator for a path, containment already proven.
     *
     * @throws WorkspaceDenied
     */
    public function resolve(string $relative, bool $mustExist = true): string
    {
        return $this->storage->locate($relative, $mustExist);
    }

    /**
     * @throws WorkspaceDenied
     */
    public function read(string $relative): string
    {
        return $this->storage->read($relative);
    }

    /**
     * A read handle on one file, for sending it somewhere byte by byte.
     *
     * Containment is proven exactly as it is for a read, because it is a read
     * -- the difference is only that the bytes never all exist in this process
     * at once. Whoever is allowed to open the handle is decided above this,
     * and the audit record for a download is written by whoever asked, because
     * "an agent read this" and "a person exported this" are different facts.
     *
     * @return resource
     *
     * @throws WorkspaceDenied
     */
    public function stream(string $relative)
    {
        return $this->storage->stream($relative);
    }

    public function size(string $relative): int
    {
        return $this->storage->size($relative);
    }

    public function isFile(string $relative): bool
    {
        return $this->storage->isFile($relative);
    }

    /**
     * Write a file, enforcing the quota and the MIME allowlist.
     *
     * @throws WorkspaceDenied
     */
    public function write(string $relative, string $contents): string
    {
        $path = $this->storage->locate($relative, mustExist: false);

        $mime = $this->detectMimeType($contents);

        if (! $this->workspace->allowsMimeType($mime)) {
            // Detected, never claimed. An extension is an assertion by
            // whoever chose the filename -- and on object storage so is
            // `Content-Type`, which is why neither is consulted.
            throw $this->denials->deny($relative, 'mime_not_allowed', ['detected_mime' => $mime]);
        }

        $existing = $this->storage->size($relative);
        $delta = strlen($contents) - $existing;

        $this->reserve($delta, $relative);

        try {
            $written = $this->storage->write($relative, $contents);
        } catch (WorkspaceDenied $e) {
            // Give the reservation back. A failed write that kept its quota
            // would shrink the workspace a little on every error until it was
            // full of nothing. This matters more on object storage, where the
            // failure is usually the network rather than the disk being full.
            $this->release($delta);

            throw $e;
        }

        // Reconcile against what actually landed rather than what was
        // expected, so a short write does not leave the counter lying.
        $this->release($delta - ($written - $existing));

        $this->audit->record(
            action: 'workspace.file_written',
            targetType: 'workspace',
            targetId: (string) $this->workspace->getKey(),
            metadata: ['path' => $relative, 'bytes' => $written, 'mime' => $mime],
        );

        return $path;
    }

    /**
     * @throws WorkspaceDenied
     */
    public function delete(string $relative): void
    {
        $size = $this->storage->size($relative);

        $this->storage->delete($relative);

        $this->release($size);

        $this->audit->record(
            action: 'workspace.file_deleted',
            targetType: 'workspace',
            targetId: (string) $this->workspace->getKey(),
            metadata: ['path' => $relative, 'bytes' => $size],
        );
    }

    /**
     * @return list<string> paths relative to the root
     *
     * @throws WorkspaceDenied
     */
    public function list(string $relative = ''): array
    {
        return $this->storage->list($relative);
    }

    /**
     * Recount the workspace from the store.
     *
     * The counter is authoritative for enforcement because reading it is one
     * query while walking a store is thousands of calls. This exists for when
     * the two disagree -- a crash mid-write, a file removed by hand -- and it
     * is the reason `used_bytes` drifting is a repairable annoyance rather
     * than a corrupted workspace.
     */
    public function reconcile(): int
    {
        $total = $this->storage->totalBytes();

        $this->workspace->update(['used_bytes' => $total]);

        return $total;
    }

    /**
     * Claim quota before the bytes land.
     *
     * A conditional UPDATE, not a read-then-write. Checking `used_bytes` and
     * then writing is the same race as Phase 4's `last_run_at` check, with the
     * same fix: make the database decide, in one statement, and treat "no rows
     * affected" as the refusal.
     *
     * @throws WorkspaceDenied
     */
    private function reserve(int $delta, string $relative): void
    {
        if ($delta <= 0 || ! $this->workspace->hasQuota()) {
            if ($delta !== 0) {
                $this->adjustUsage($delta);
            }

            return;
        }

        $quota = (int) $this->workspace->quota_bytes;

        // `increment` on a conditional `where`, so the check and the claim are
        // one statement and the database arbitrates. Two writers racing for
        // the last bytes cannot both see room; exactly one update affects a
        // row, and the other gets the refusal.
        $affected = $this->table()
            ->where('id', $this->workspace->getKey())
            ->where('used_bytes', '<=', $quota - $delta)
            ->increment('used_bytes', $delta);

        if ($affected === 0) {
            $this->audit->record(
                action: 'workspace.quota_exceeded',
                targetType: 'workspace',
                targetId: (string) $this->workspace->getKey(),
                severity: 'warning',
                metadata: ['path' => $relative, 'requested_bytes' => $delta, 'quota_bytes' => $quota],
            );

            throw WorkspaceDenied::quotaExceeded($relative, $delta, $quota);
        }

        $this->workspace->refresh();
    }

    private function release(int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $this->adjustUsage(-$delta);
    }

    private function adjustUsage(int $delta): void
    {
        // Read, clamp, write, under a row lock. `used_bytes` is unsigned, so
        // letting it go negative is a database error on three engines and
        // silent wraparound on the fourth -- and the clamp cannot be expressed
        // portably in a single UPDATE without raw SQL per dialect.
        $this->workspace->getConnection()->transaction(function () use ($delta): void {
            $row = $this->table()
                ->where('id', $this->workspace->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return;
            }

            /** @var array{used_bytes: int|string} $fields */
            $fields = (array) $row;

            $this->table()
                ->where('id', $this->workspace->getKey())
                ->update(['used_bytes' => max(0, (int) $fields['used_bytes'] + $delta)]);
        });

        $this->workspace->refresh();
    }

    private function table(): Builder
    {
        return $this->workspace->getConnection()->table($this->workspace->getTable());
    }

    /**
     * Guess a MIME type from the CONTENT.
     *
     * `finfo` reads magic bytes; the filename is never consulted, and neither
     * is any metadata the store carries. Falls back to `text/plain` only when
     * finfo is unavailable, and that fallback is the conservative direction: a
     * workspace restricted to images will refuse an unidentifiable file rather
     * than accept it.
     */
    private function detectMimeType(string $contents): string
    {
        if (! class_exists(\finfo::class)) {
            return 'text/plain';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($contents);

        return $detected === false ? 'application/octet-stream' : $detected;
    }
}
