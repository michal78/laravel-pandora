<?php

declare(strict_types=1);

namespace Pandora\Context;

use Pandora\Exceptions\ContextFileDenied;

/**
 * Loads context files, and only from the roots an operator configured.
 *
 * A path reaching this class is attacker-influenced input in any application
 * with an admin UI: agent configuration is edited in a browser, and "which
 * files does this agent read" is a text field. `/etc/passwd`,
 * `../../.env` and a symlink pointing at either are all ordinary strings until
 * something resolves them.
 *
 * Containment is checked AFTER resolution, never before. A check against the
 * string a caller passed is a check against a spelling: `../` can be spelled
 * a dozen ways, and a symlink is not spelled at all. `realpath()` collapses
 * every one of them into the single question worth asking -- what file is this
 * actually -- and only then is the prefix compared.
 *
 * The prefix comparison includes a trailing separator on purpose. Without it
 * a root of `/srv/agent` accepts `/srv/agent-secrets`, which is a real bug
 * with a boring name.
 */
final class ContextFiles
{
    /** @var int<1, max> */
    private readonly int $maxBytes;

    /**
     * @param list<string> $roots
     */
    public function __construct(
        private readonly array $roots,
        int $maxBytes = 65536,
    ) {
        // A configured zero or a negative -- a plausible typo in a published
        // config file -- would otherwise reach fread() and throw mid-run,
        // taking down every agent with a context file. One byte is a useless
        // read but an honest one.
        $this->maxBytes = max(1, $maxBytes);
    }

    public static function fromConfig(): self
    {
        /** @var list<string> $roots */
        $roots = config('pandora.context.files.roots', []);
        /** @var int $maxBytes */
        $maxBytes = config('pandora.context.files.max_bytes', 65536);

        return new self($roots, $maxBytes);
    }

    /**
     * Resolve a configured path to a real file inside a configured root.
     *
     * @throws ContextFileDenied
     */
    public function resolve(string $path): string
    {
        if ($this->roots === []) {
            // No roots configured means the feature is off. Refusing is the
            // only safe reading: an empty allowlist that permits everything
            // is how this class would fail open on a default install.
            throw ContextFileDenied::noRootsConfigured($path);
        }

        $real = realpath($path);

        if ($real === false || ! is_file($real)) {
            // Deliberately the same refusal as an out-of-root path. A distinct
            // "no such file" answer turns this into an oracle that reports
            // which paths exist outside the allowed roots.
            throw ContextFileDenied::outsideRoots($path);
        }

        foreach ($this->roots as $root) {
            $realRoot = realpath($root);

            if ($realRoot === false) {
                continue;
            }

            if (str_starts_with($real, rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        throw ContextFileDenied::outsideRoots($path);
    }

    /**
     * @throws ContextFileDenied
     */
    public function read(string $path): string
    {
        $real = $this->resolve($path);

        $handle = fopen($real, 'rb');

        if ($handle === false) {
            throw ContextFileDenied::unreadable($path);
        }

        try {
            // Bounded read rather than file_get_contents: a context file is
            // budgeted, and a 2GB log accidentally named in configuration
            // should cost one truncated read, not the worker's memory limit.
            $contents = fread($handle, $this->maxBytes);
        } finally {
            fclose($handle);
        }

        return $contents === false ? '' : $contents;
    }

    /**
     * @param list<string> $paths
     * @return array<string, string> real path => contents, skipping refusals
     */
    public function readAll(array $paths): array
    {
        $loaded = [];

        foreach ($paths as $path) {
            try {
                $loaded[$this->resolve($path)] = $this->read($path);
            } catch (ContextFileDenied) {
                // A refused file is an omission on the trace, not a failed
                // run: one bad path in an agent's configuration should not
                // take the agent offline.
                continue;
            }
        }

        return $loaded;
    }
}
