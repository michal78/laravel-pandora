<?php

declare(strict_types=1);

namespace Pandora\Workspaces;

use Pandora\Core\Tenancy\TenantManager;
use Pandora\Exceptions\WorkspaceDenied;

/**
 * The roots a workspace may be created under, and the only way to compose one.
 *
 * Phase 5 deferred the creation surface over a single question: a workspace is
 * somewhere an agent may read and write, and every guarantee about it reduces
 * to *who chose the root?* The obvious form has a path field, and a form with
 * a path field is a form that accepts `/`.
 *
 * The answer here is that a request chooses a KEY, never a path. An operator
 * declares a small number of roots in `pandora.workspaces.roots`; the UI
 * offers them by key; and the path is composed from the root's base prefix,
 * the current tenant and a slug derived from the name. Nothing a browser sends
 * reaches `disk` or `root_path` except through this class, and this class
 * cannot produce a root that was not declared.
 *
 * An empty root list permits nothing. That is the correct direction for an
 * allowlist and the opposite of the MIME list above it: a MIME allowlist
 * narrows what may enter an already-bounded workspace, so empty means "all
 * types". A root list decides where the boundary *is*, so empty means "no
 * workspace can be created" rather than "anywhere".
 */
final readonly class WorkspaceRoots
{
    public function __construct(private TenantManager $tenants) {}

    /**
     * Every declared root, keyed by its key.
     *
     * @return array<string, Root>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = config('pandora.workspaces.roots', []);

        $roots = [];

        foreach ($configured as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $disk = isset($definition['disk']) ? trim((string) $definition['disk']) : '';

            // A root with no disk is not a half-configured root, it is a typo.
            // Skipped rather than defaulted, because defaulting it to the
            // local disk would silently offer a root nobody declared.
            if ($disk === '') {
                continue;
            }

            $key = (string) $key;

            $roots[$key] = new Root(
                key: $key,
                label: isset($definition['label']) ? (string) $definition['label'] : $key,
                disk: $disk,
                basePrefix: isset($definition['base_prefix']) ? (string) $definition['base_prefix'] : '',
            );
        }

        return $roots;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * The root a key names.
     *
     * Refused rather than defaulted when the key is unknown. There is no
     * "closest match" and no fallback root: an unrecognised key means the
     * request named something an operator did not declare, and the only safe
     * reading of that is no.
     *
     * @throws WorkspaceDenied
     */
    public function get(string $key): Root
    {
        $root = $this->all()[$key] ?? null;

        if ($root === null) {
            throw WorkspaceDenied::rootNotPermitted($key);
        }

        return $root;
    }

    /**
     * Compose the `root_path` for a new workspace under a declared root.
     *
     * `<base>/<tenant>/<slug>`. The slug is derived from the name by the
     * caller and re-checked here, because "derived from the name" is only a
     * guarantee while the derivation is the one that ran.
     *
     * @throws WorkspaceDenied
     */
    public function compose(Root $root, string $slug): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug) !== 1) {
            // Belt and braces on top of the caller's slug generation. This is
            // the last point before a browser-supplied string becomes part of
            // a path, so it is checked against what a slug may contain rather
            // than what it may not.
            throw WorkspaceDenied::rootNotPermitted($root->key);
        }

        $segments = [$this->tenantSegment(), $slug];
        $base = $root->basePrefix;

        if ($this->isLocal($root)) {
            return $this->localBase($root).'/'.implode('/', $segments);
        }

        $base = trim($base, '/');

        return ($base === '' ? '' : $base.'/').implode('/', $segments);
    }

    /**
     * Make sure the composed root exists, for the kinds of storage where that
     * is a thing that can be true.
     *
     * A filesystem root has to be created before anything resolves inside it:
     * `LocalStorage` measures containment with `realpath()`, and `realpath()`
     * of a directory nobody made is `false`. Object storage has no
     * directories, so a prefix with no objects under it is already exactly as
     * real as it will ever be, and there is nothing here to do.
     */
    public function prepare(Root $root, string $path): void
    {
        if (! $this->isLocal($root)) {
            return;
        }

        if (! is_dir($path)) {
            mkdir($path, 0755, recursive: true);
        }
    }

    /**
     * The tenant's own segment of the path.
     *
     * Present even when tenancy is off, where it is the constant `shared`, so
     * that turning tenancy ON later does not change the meaning of a path
     * already written. A tenant id that is not already path-safe is hashed
     * rather than sanitised: replacing the awkward characters would map
     * `acme/eu` and `acme-eu` onto one directory, and two tenants sharing a
     * workspace is the failure this segment exists to prevent.
     */
    private function tenantSegment(): string
    {
        $tenantId = $this->tenants->currentId();

        if ($tenantId === null || $tenantId === '') {
            return 'shared';
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $tenantId) === 1
            ? $tenantId
            : 't-'.substr(hash('sha256', $tenantId), 0, 32);
    }

    /**
     * Decided by the disk's driver, never by its name -- the same rule
     * `StorageFactory` uses, and for the same reason: a host is free to call
     * its bucket `local`.
     */
    private function isLocal(Root $root): bool
    {
        /** @var string|null $driver */
        $driver = config("filesystems.disks.{$root->disk}.driver");

        return $driver === null || $driver === 'local';
    }

    /**
     * Where a local root's base prefix is measured from.
     *
     * An absolute base prefix is taken as given: it is operator configuration,
     * and the operator declaring where workspaces may live is exactly the
     * authority this class defers to. A relative one is resolved against the
     * disk's own root, so `pandora-workspaces` lands inside the application's
     * storage rather than wherever the process happened to start.
     */
    private function localBase(Root $root): string
    {
        $base = $root->basePrefix;

        if (str_starts_with($base, '/')) {
            return rtrim($base, '/');
        }

        /** @var string|null $diskRoot */
        $diskRoot = config("filesystems.disks.{$root->disk}.root");
        $diskRoot = is_string($diskRoot) && $diskRoot !== ''
            ? rtrim($diskRoot, '/')
            : rtrim(storage_path('app'), '/');

        $base = trim($base, '/');

        return $base === '' ? $diskRoot : $diskRoot.'/'.$base;
    }
}
