<?php

declare(strict_types=1);

namespace Pandora\Context;

use Pandora\Exceptions\ContextFileDenied;

/**
 * A context file named on a disk rather than on the filesystem.
 *
 * Written `disk:<name>/<key>` — `disk:objects/handbooks/support.md`. The
 * prefix is explicit rather than inferred, because a bare string that might be
 * a path and might be a key is a string somebody eventually resolves the wrong
 * way. Anything without it is a filesystem path and goes through `realpath()`
 * as it always did.
 *
 * Normalisation is the object store's rules (ADR-0013), and identical to the
 * workspace adapter's for the same reasons: `..` is refused rather than
 * resolved, a backslash is a separator somewhere, a scheme is honoured by some
 * clients, and a leading slash is somebody assuming the prefix is advisory.
 */
final readonly class ObjectContextPath
{
    public function __construct(
        public string $disk,
        public string $key,
        public string $original,
    ) {}

    public static function looksLikeOne(string $path): bool
    {
        return str_starts_with($path, 'disk:');
    }

    /**
     * @throws ContextFileDenied
     */
    public static function parse(string $path): self
    {
        $remainder = substr($path, strlen('disk:'));
        $slash = strpos($remainder, '/');

        if ($slash === false || $slash === 0) {
            throw ContextFileDenied::outsideRoots($path);
        }

        $disk = substr($remainder, 0, $slash);
        $key = self::normalise(substr($remainder, $slash + 1), $path);

        return new self($disk, $key, $path);
    }

    /**
     * Is this path inside the given root, which must also be a disk path?
     */
    public function isUnder(self $root): bool
    {
        if ($this->disk !== $root->disk) {
            return false;
        }

        // The trailing delimiter is load-bearing, exactly as it is for a
        // workspace prefix and for a filesystem root: without it a root of
        // `handbooks` accepts `handbooks-internal/salaries.md`.
        return str_starts_with($this->key, rtrim($root->key, '/').'/');
    }

    /**
     * @throws ContextFileDenied
     */
    private static function normalise(string $key, string $original): string
    {
        if (str_contains($key, "\0")) {
            throw ContextFileDenied::outsideRoots($original);
        }

        $key = str_replace('\\', '/', $key);

        if (str_starts_with($key, '/') || preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $key) === 1) {
            throw ContextFileDenied::outsideRoots($original);
        }

        $segments = [];

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw ContextFileDenied::outsideRoots($original);
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw ContextFileDenied::outsideRoots($original);
        }

        return implode('/', $segments);
    }
}
