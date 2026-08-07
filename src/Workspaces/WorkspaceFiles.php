<?php

declare(strict_types=1);

namespace Pandora\Workspaces;

use Illuminate\Database\Query\Builder;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\WorkspaceDenied;

/**
 * Reads and writes inside a workspace, and nowhere else.
 *
 * Containment is checked AFTER resolution, on EVERY operation, and both halves
 * of that matter for different reasons.
 *
 * *After resolution*, because a check against the string a caller passed is a
 * check against a spelling. `../` has a dozen spellings and a symlink has none
 * at all -- it is simply a path inside the root that is not a file inside the
 * root. Only `realpath()` answers the question worth asking: which file is
 * this, actually.
 *
 * *Every operation*, because resolving once and using twice is a TOCTOU window
 * that a symlink planted between the two fits through exactly. There is no
 * "already validated" fast path here, and there should never be one.
 *
 * Paths from an agent are treated as hostile throughout. That is not paranoia
 * about the model: a tool argument is downstream of every document the agent
 * has read this run, and one of those documents is allowed to be a web page.
 */
final class WorkspaceFiles
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The absolute, resolved root. Every path must live under this.
     *
     * @throws WorkspaceDenied
     */
    public function root(): string
    {
        $root = realpath($this->workspace->root_path);

        if ($root === false || ! is_dir($root)) {
            throw WorkspaceDenied::rootMissing($this->workspace->root_path);
        }

        return $root;
    }

    /**
     * Resolve a relative path to an absolute one inside the workspace.
     *
     * @param bool $mustExist false when resolving a path about to be created,
     *                        in which case the PARENT directory is resolved
     *                        and checked instead -- a file that does not exist
     *                        yet has no realpath, and refusing to create
     *                        anything would make the workspace read-only.
     *
     * @throws WorkspaceDenied
     */
    public function resolve(string $relative, bool $mustExist = true): string
    {
        $root = $this->root();

        if (str_contains($relative, "\0")) {
            // A null byte truncates the path at the C level, so everything
            // after it is invisible to the checks below and visible to the
            // filesystem.
            throw $this->deny($relative, 'null_byte');
        }

        $candidate = $root.DIRECTORY_SEPARATOR.ltrim($relative, DIRECTORY_SEPARATOR);

        if ($mustExist) {
            $real = realpath($candidate);

            if ($real === false) {
                throw $this->deny($relative, 'not_found');
            }

            $this->assertContained($real, $root, $relative);

            return $real;
        }

        $parent = realpath(dirname($candidate));

        if ($parent === false) {
            throw $this->deny($relative, 'not_found');
        }

        $this->assertContained($parent, $root, $relative, allowRootItself: true);

        $target = $parent.DIRECTORY_SEPARATOR.basename($candidate);

        // The parent being contained is NOT sufficient, and assuming it was is
        // a genuine hole: `notes.txt` can be a symlink to somewhere else
        // entirely, and every write call that follows would happily follow it.
        // If the target already exists in any form, it gets resolved and
        // checked exactly like a read would. A path that does not exist yet
        // has nothing to follow, so a contained parent is the whole answer.
        if (is_link($target) || file_exists($target)) {
            $real = realpath($target);

            if ($real === false) {
                // A dangling symlink: it exists as a link, resolves to
                // nothing. Refused rather than created, because creating it
                // would write through the link to wherever it points.
                throw $this->deny($relative, 'outside_root');
            }

            $this->assertContained($real, $root, $relative, allowRootItself: false);

            return $real;
        }

        return $target;
    }

    /**
     * @throws WorkspaceDenied
     */
    public function read(string $relative): string
    {
        $path = $this->resolve($relative);

        if (! is_file($path)) {
            throw $this->deny($relative, 'not_a_file');
        }

        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    /**
     * Write a file, enforcing the quota and the MIME allowlist.
     *
     * @throws WorkspaceDenied
     */
    public function write(string $relative, string $contents): string
    {
        $path = $this->resolve($relative, mustExist: false);

        $mime = $this->detectMimeType($contents);

        if (! $this->workspace->allowsMimeType($mime)) {
            // Detected, never claimed. An extension is an assertion by
            // whoever chose the filename.
            throw $this->deny($relative, 'mime_not_allowed', ['detected_mime' => $mime]);
        }

        $existing = is_file($path) ? (int) filesize($path) : 0;
        $delta = strlen($contents) - $existing;

        $this->reserve($delta, $relative);

        $written = file_put_contents($path, $contents);

        if ($written === false) {
            // Give the reservation back. A failed write that kept its quota
            // would shrink the workspace a little on every error until it was
            // full of nothing.
            $this->release($delta);

            throw $this->deny($relative, 'unwritable');
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
        $path = $this->resolve($relative);

        if (! is_file($path)) {
            throw $this->deny($relative, 'not_a_file');
        }

        $size = (int) filesize($path);

        if (! unlink($path)) {
            throw $this->deny($relative, 'unwritable');
        }

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
        $root = $this->root();
        $directory = $relative === '' ? $root : $this->resolve($relative);

        if (! is_dir($directory)) {
            throw $this->deny($relative, 'not_a_directory');
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return [];
        }

        $listed = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = realpath($directory.DIRECTORY_SEPARATOR.$entry);

            // A symlink pointing out of the workspace is omitted from the
            // listing as well as being unreadable. Listing it would tell an
            // agent that a file it may not read exists, which is the same
            // information leak in a smaller package.
            if ($full === false || ! $this->isContained($full, $root)) {
                continue;
            }

            $listed[] = ltrim(substr($full, strlen($root)), DIRECTORY_SEPARATOR);
        }

        sort($listed);

        return $listed;
    }

    /**
     * Recount the workspace from the filesystem.
     *
     * The counter is authoritative for enforcement because reading it is one
     * query while walking a tree is thousands of syscalls. This exists for
     * when the two disagree -- a crash mid-write, a file removed by hand --
     * and it is the reason `used_bytes` drifting is a repairable annoyance
     * rather than a corrupted workspace.
     */
    public function reconcile(): int
    {
        $root = $this->root();
        $total = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && ! $file->isLink()) {
                $total += $file->getSize();
            }
        }

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
     * @throws WorkspaceDenied
     */
    private function assertContained(string $real, string $root, string $relative, bool $allowRootItself = false): void
    {
        if ($allowRootItself && $real === $root) {
            return;
        }

        if (! $this->isContained($real, $root)) {
            throw $this->deny($relative, 'outside_root', ['resolved_to' => $real]);
        }
    }

    private function isContained(string $real, string $root): bool
    {
        // The trailing separator is load-bearing. Without it a root of
        // `/srv/agent` accepts `/srv/agent-secrets`, which is a real bug with
        // a boring name.
        return str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    /**
     * Guess a MIME type from the CONTENT.
     *
     * `finfo` reads magic bytes; the filename is never consulted. Falls back
     * to `text/plain` only when finfo is unavailable, and that fallback is the
     * conservative direction: a workspace restricted to images will refuse an
     * unidentifiable file rather than accept it.
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

    /**
     * @param array<string, mixed> $extra
     */
    private function deny(string $relative, string $reason, array $extra = []): WorkspaceDenied
    {
        // Critical, not warning. A path that resolved outside its root is
        // either a bug in this class or somebody probing, and both deserve to
        // wake somebody up.
        $severity = $reason === 'outside_root' || $reason === 'null_byte' ? 'critical' : 'info';

        $this->audit->record(
            action: $severity === 'critical' ? 'workspace.containment_violation' : 'workspace.access_denied',
            targetType: 'workspace',
            targetId: (string) $this->workspace->getKey(),
            severity: $severity,
            metadata: array_merge(['path' => $relative, 'reason' => $reason], $extra),
        );

        return WorkspaceDenied::path($relative, $reason);
    }
}
