<?php

declare(strict_types=1);

namespace Pandora\Workspaces\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Workspaces\Denials;
use Pandora\Workspaces\Workspace;

/**
 * A workspace on S3-compatible object storage.
 *
 * Containment here is a different problem from the filesystem's, and a simpler
 * one, because the things that make the filesystem hard are all absent. There
 * are no symlinks, so nothing points anywhere. There are no directories, so
 * nothing can be climbed out of. A key is not a *name for* an object; it is
 * the object, and no second key reaches the same bytes.
 *
 * So the answer is lexical, and being lexical is not a weakness here the way
 * it would be on a filesystem: there is no second, truer form of a key to
 * resolve to. Normalise, refuse anything that escapes, then prefix.
 *
 * What object storage adds instead is a store that can be *unreachable*, and
 * metadata that is *attacker-controlled*. Both are handled here: an
 * unreachable disk is a refusal rather than a fallback (ADR-0013), and no
 * decision anywhere consults `Content-Type`, which is whatever the uploader
 * said it was.
 */
final readonly class ObjectStorage implements WorkspaceStorage
{
    public function __construct(
        private Workspace $workspace,
        private Filesystem $disk,
        private Denials $denials,
    ) {}

    public function assertAvailable(): void
    {
        try {
            // A cheap read against the prefix. It is allowed to be empty --
            // what is being asked is whether the store answers at all.
            $this->disk->directories($this->prefix());
        } catch (\Throwable $e) {
            throw WorkspaceDenied::diskUnavailable($this->workspace->disk, $e->getMessage());
        }
    }

    public function locate(string $relative, bool $mustExist = true): string
    {
        return $this->prefix().$this->normalise($relative);
    }

    public function isFile(string $relative): bool
    {
        $key = $this->locate($relative);

        // `exists()` is Flysystem's `has()` -- true for a prefix as well as an
        // object -- and the contract here says false for a prefix. The local
        // adapter has always answered `is_file()`, so the two disagreed about
        // the same question until a walkthrough clicked Download on a folder.
        return $this->guard(fn (): bool => $this->disk->fileExists($key));
    }

    public function read(string $relative): string
    {
        $key = $this->locate($relative);

        if (! $this->guard(fn (): bool => $this->disk->exists($key))) {
            throw $this->denials->deny($relative, 'not_a_file');
        }

        return $this->guard(fn (): string => (string) $this->disk->get($key));
    }

    /**
     * @return resource
     */
    public function stream(string $relative)
    {
        $key = $this->locate($relative);

        if (! $this->guard(fn (): bool => $this->disk->exists($key))) {
            throw $this->denials->deny($relative, 'not_a_file');
        }

        $handle = $this->guard(fn () => $this->disk->readStream($key));

        if (! is_resource($handle)) {
            throw $this->denials->deny($relative, 'unreadable');
        }

        return $handle;
    }

    public function write(string $relative, string $contents): int
    {
        $key = $this->locate($relative, mustExist: false);

        $ok = $this->guard(fn (): bool => $this->disk->put($key, $contents) !== false);

        if (! $ok) {
            throw $this->denials->deny($relative, 'unwritable');
        }

        // Object writes are all-or-nothing: there is no short write to
        // reconcile against, unlike a filesystem that can fill mid-stream.
        return strlen($contents);
    }

    public function delete(string $relative): void
    {
        $key = $this->locate($relative);

        if (! $this->guard(fn (): bool => $this->disk->exists($key))) {
            throw $this->denials->deny($relative, 'not_a_file');
        }

        if (! $this->guard(fn (): bool => $this->disk->delete($key))) {
            throw $this->denials->deny($relative, 'unwritable');
        }
    }

    public function size(string $relative): int
    {
        $key = $this->locate($relative);

        if (! $this->guard(fn (): bool => $this->disk->exists($key))) {
            return 0;
        }

        return $this->guard(fn (): int => (int) $this->disk->size($key));
    }

    public function list(string $relative = ''): array
    {
        $prefix = $this->prefix();
        $under = $relative === '' ? $prefix : $this->locate($relative, mustExist: false).'/';

        // Non-recursive, to match the filesystem adapter's one-level listing.
        // Laravel walks Flysystem's generator for us, so a prefix holding more
        // objects than one API page returns all of them rather than the first
        // thousand.
        $keys = $this->guard(fn (): array => array_merge(
            $this->disk->files($under),
            $this->disk->directories($under),
        ));

        $listed = [];

        foreach ($keys as $key) {
            // Belt and braces. A store that returned a key outside the prefix
            // we asked about would be misbehaving, and the answer to a
            // misbehaving store is not to relay what it said.
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $listed[] = substr($key, strlen($prefix));
        }

        sort($listed);

        return $listed;
    }

    public function totalBytes(): int
    {
        $prefix = $this->prefix();
        $total = 0;

        foreach ($this->guard(fn (): array => $this->disk->allFiles($prefix)) as $key) {
            $total += (int) $this->guard(fn (): int => (int) $this->disk->size($key));
        }

        return $total;
    }

    /**
     * The workspace's key prefix, always ending in the delimiter.
     *
     * The trailing slash is load-bearing in exactly the way the filesystem's
     * trailing separator is: without it a prefix of `tenant-1` matches
     * `tenant-10/secrets.txt`, and one tenant reads another's workspace
     * through a listing that looks entirely ordinary.
     */
    private function prefix(): string
    {
        $root = trim($this->workspace->root_path, '/');

        return $root === '' ? '' : $root.'/';
    }

    /**
     * Reduce a caller's path to a safe key fragment, or refuse it.
     *
     * @throws WorkspaceDenied
     */
    private function normalise(string $relative): string
    {
        if (str_contains($relative, "\0")) {
            // No C-level truncation to fear here, but a null byte in a key is
            // never anything but an attempt at one, and it travels onward into
            // logs, URLs and XML the store parses.
            throw $this->denials->deny($relative, 'null_byte');
        }

        // A backslash is a separator on some clients and an ordinary character
        // in a key. Normalising it means `..\..\etc` cannot walk out past a
        // check that only knows about `/`.
        $path = str_replace('\\', '/', $relative);

        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path) === 1) {
            // `s3://other-bucket/…`, `file:///etc/passwd`. Some clients honour
            // a scheme in a path; none of them should be given the chance.
            throw $this->denials->deny($relative, 'outside_root');
        }

        if (str_starts_with($path, '/')) {
            // An absolute key is not a key at all -- it is somebody assuming
            // the prefix will be ignored.
            throw $this->denials->deny($relative, 'outside_root');
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                // Refused, never popped. Resolving `a/../b` to `b` would
                // quietly accept a path whose author was trying to leave, and
                // the one that leaves entirely is the same expression with one
                // more segment.
                throw $this->denials->deny($relative, 'outside_root');
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw $this->denials->deny($relative, 'not_a_file');
        }

        return implode('/', $segments);
    }

    /**
     * Run a store call, turning any transport failure into a refusal.
     *
     * The disk being down must not surface as a Flysystem exception halfway up
     * the run: it is an ordinary tool error, the run continues, and nothing is
     * written anywhere else instead.
     *
     * @template T
     *
     * @param callable(): T $call
     * @return T
     *
     * @throws WorkspaceDenied
     */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (WorkspaceDenied $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw WorkspaceDenied::diskUnavailable($this->workspace->disk, $e->getMessage());
        }
    }
}
