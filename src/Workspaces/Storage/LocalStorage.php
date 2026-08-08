<?php

declare(strict_types=1);

namespace Pandora\Workspaces\Storage;

use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Workspaces\Denials;
use Pandora\Workspaces\Workspace;

/**
 * A workspace on a real filesystem.
 *
 * This is the Phase 5 implementation, moved behind the seam and otherwise
 * unchanged. It was not rewritten to go through Flysystem's local adapter, and
 * that is a decision rather than an omission: Flysystem prefixes and then
 * checks the prefix, which is a check against a *spelling*. What is here
 * resolves first and checks what it resolved, which is the only version that
 * survives a symlink.
 *
 * Containment is checked AFTER resolution, on EVERY operation, and both halves
 * matter for different reasons.
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
 */
final readonly class LocalStorage implements WorkspaceStorage
{
    public function __construct(
        private Workspace $workspace,
        private Denials $denials,
    ) {}

    public function assertAvailable(): void
    {
        $this->root();
    }

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

    public function locate(string $relative, bool $mustExist = true): string
    {
        $root = $this->root();

        if (str_contains($relative, "\0")) {
            // A null byte truncates the path at the C level, so everything
            // after it is invisible to the checks below and visible to the
            // filesystem.
            throw $this->denials->deny($relative, 'null_byte');
        }

        $candidate = $root.DIRECTORY_SEPARATOR.ltrim($relative, DIRECTORY_SEPARATOR);

        if ($mustExist) {
            $real = realpath($candidate);

            if ($real === false) {
                throw $this->denials->deny($relative, 'not_found');
            }

            $this->assertContained($real, $root, $relative);

            return $real;
        }

        $parent = realpath(dirname($candidate));

        if ($parent === false) {
            throw $this->denials->deny($relative, 'not_found');
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
                throw $this->denials->deny($relative, 'outside_root');
            }

            $this->assertContained($real, $root, $relative, allowRootItself: false);

            return $real;
        }

        return $target;
    }

    public function isFile(string $relative): bool
    {
        try {
            return is_file($this->locate($relative));
        } catch (WorkspaceDenied $e) {
            if ($e->reason === 'not_found') {
                return false;
            }

            throw $e;
        }
    }

    public function read(string $relative): string
    {
        $path = $this->locate($relative);

        if (! is_file($path)) {
            throw $this->denials->deny($relative, 'not_a_file');
        }

        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    /**
     * @return resource
     */
    public function stream(string $relative)
    {
        // Located again rather than reusing anything: a handle is opened here
        // and read later, and the whole point of re-resolving on every
        // operation is that "later" is where a symlink gets planted.
        $path = $this->locate($relative);

        if (! is_file($path)) {
            throw $this->denials->deny($relative, 'not_a_file');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw $this->denials->deny($relative, 'unreadable');
        }

        return $handle;
    }

    public function write(string $relative, string $contents): int
    {
        $path = $this->locate($relative, mustExist: false);

        $written = file_put_contents($path, $contents);

        if ($written === false) {
            throw $this->denials->deny($relative, 'unwritable');
        }

        return $written;
    }

    public function delete(string $relative): void
    {
        $path = $this->locate($relative);

        if (! is_file($path)) {
            throw $this->denials->deny($relative, 'not_a_file');
        }

        if (! unlink($path)) {
            throw $this->denials->deny($relative, 'unwritable');
        }
    }

    public function size(string $relative): int
    {
        try {
            $path = $this->locate($relative);
        } catch (WorkspaceDenied $e) {
            if ($e->reason === 'not_found') {
                return 0;
            }

            throw $e;
        }

        return is_file($path) ? (int) filesize($path) : 0;
    }

    public function list(string $relative = ''): array
    {
        $root = $this->root();
        $directory = $relative === '' ? $root : $this->locate($relative);

        if (! is_dir($directory)) {
            throw $this->denials->deny($relative, 'not_a_directory');
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

    public function totalBytes(): int
    {
        $total = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && ! $file->isLink()) {
                $total += $file->getSize();
            }
        }

        return $total;
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
            throw $this->denials->deny($relative, 'outside_root', ['resolved_to' => $real]);
        }
    }

    private function isContained(string $real, string $root): bool
    {
        // The trailing separator is load-bearing. Without it a root of
        // `/srv/agent` accepts `/srv/agent-secrets`, which is a real bug with
        // a boring name.
        return str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
