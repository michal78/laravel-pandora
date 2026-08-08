<?php

declare(strict_types=1);

namespace Pandora\Workspaces\Storage;

use Pandora\Exceptions\WorkspaceDenied;

/**
 * Where a workspace's bytes actually live.
 *
 * There are two implementations and they do NOT share their containment logic,
 * deliberately (ADR-0013). A filesystem and an object store are different
 * problems wearing the same words:
 *
 *   - On a filesystem a path is a name for a file that something else may also
 *     name. `../` has a dozen spellings and a symlink has none at all, so the
 *     only honest question is "which file is this, actually", and only
 *     `realpath()` answers it. It must be asked again on every operation,
 *     because a link planted between two calls fits through the gap.
 *   - In an object store a key is the object. There are no links to follow, no
 *     directories to climb and no second name for the same bytes, so lexical
 *     normalisation is the whole answer rather than a first pass at one.
 *
 * A shared base class would have to be right about both at once. The version
 * of this that gets written by accident -- prefix the root, check the string
 * starts with it -- is wrong on the filesystem in a way no test notices,
 * because every test that would catch it passes a path that has no symlink in
 * it.
 *
 * Everything above the seam is common: quota, MIME policy, audit. Those are
 * `WorkspaceFiles`, and they do not care where the bytes went.
 */
interface WorkspaceStorage
{
    /**
     * The adapter-native locator for a relative path -- an absolute filesystem
     * path, or a full object key -- after containment has been proven.
     *
     * @param bool $mustExist false when resolving something about to be
     *                        created, which on a filesystem means the PARENT
     *                        is resolved and checked instead
     *
     * @throws WorkspaceDenied
     */
    public function locate(string $relative, bool $mustExist = true): string;

    /**
     * Is there a readable file here? False for a directory, a prefix, or
     * nothing at all.
     *
     * @throws WorkspaceDenied when the path is not contained
     */
    public function isFile(string $relative): bool;

    /**
     * @throws WorkspaceDenied
     */
    public function read(string $relative): string;

    /**
     * A read handle on one file, for sending bytes somewhere without holding
     * them all at once.
     *
     * Separate from `read()` because the caller is different in kind: `read()`
     * serves an agent, which is going to put the contents in a model request
     * and is bounded by that. A download serves a browser, and a workspace is
     * allowed to hold a file larger than the worker's memory limit.
     *
     * @return resource
     *
     * @throws WorkspaceDenied
     */
    public function stream(string $relative);

    /**
     * @return int bytes actually written, which is not always what was asked
     *
     * @throws WorkspaceDenied
     */
    public function write(string $relative, string $contents): int;

    /**
     * @throws WorkspaceDenied
     */
    public function delete(string $relative): void;

    /**
     * The size of one file, or 0 when it does not exist.
     *
     * @throws WorkspaceDenied when the path is not contained
     */
    public function size(string $relative): int;

    /**
     * Paths relative to the root, sorted.
     *
     * @return list<string>
     *
     * @throws WorkspaceDenied
     */
    public function list(string $relative = ''): array;

    /**
     * Every byte the workspace holds, counted from the store itself.
     *
     * The expensive truth, for reconciling a counter that has drifted.
     *
     * @throws WorkspaceDenied
     */
    public function totalBytes(): int;

    /**
     * Fail now, with a reason, if the underlying store cannot be reached.
     *
     * Called before work rather than discovered during it. An unreachable disk
     * is a tool error and never a quiet write somewhere else (ADR-0013).
     *
     * @throws WorkspaceDenied
     */
    public function assertAvailable(): void;
}
