<?php

declare(strict_types=1);

namespace Pandora\Extensions;

/**
 * Finds installed extensions by reading a JSON file. That is the whole design.
 *
 * `vendor/composer/installed.json` is written by Composer, lists every installed
 * package with its `extra` block and its autoload prefixes, and is on disk
 * before anything boots. Reading it involves no autoloading, no
 * `class_exists()`, no reflection and no service provider — so the Extensions
 * page can describe a package that has never been loaded, including one that
 * would fatal if it were.
 *
 * That last case is not hypothetical and it is the reason this class exists in
 * this shape. The extension an operator most needs to look at is the broken one,
 * and a page that boots every candidate in order to render a table cannot show
 * it to them. Inspecting a thing must not require running it (ADR-0016).
 *
 * There is deliberately nothing here that fetches, installs, updates or checks
 * for a newer version of anything. This class reads one local file.
 */
final class ExtensionDiscovery
{
    /** @var list<array{manifest: ExtensionManifest, prefixes: list<string>}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $installedJsonPath,
    ) {}

    /**
     * Every installed package carrying an `extra.pandora` block.
     *
     * @return list<ExtensionManifest>
     */
    public function manifests(): array
    {
        return array_map(
            static fn (array $entry): ExtensionManifest => $entry['manifest'],
            $this->read(),
        );
    }

    /**
     * The package a class belongs to, by PSR-4 prefix, or null.
     *
     * Longest prefix wins, because `Vendor\Slack\` and `Vendor\` may both be
     * declared and only one of them is the answer.
     */
    public function packageFor(string $class): ?string
    {
        $best = null;
        $bestLength = 0;

        foreach ($this->read() as $entry) {
            foreach ($entry['prefixes'] as $prefix) {
                $length = strlen($prefix);

                if ($length > $bestLength && str_starts_with($class, $prefix)) {
                    $best = $entry['manifest']->package;
                    $bestLength = $length;
                }
            }
        }

        return $best;
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    /**
     * @return list<array{manifest: ExtensionManifest, prefixes: list<string>}>
     */
    private function read(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        // A missing or unreadable file is an empty list, not an error. A
        // production install always has one; a package's own test suite running
        // under Testbench frequently does not, and "no extensions installed" is
        // the honest answer in both cases.
        if (! is_file($this->installedJsonPath) || ! is_readable($this->installedJsonPath)) {
            return $this->cache = [];
        }

        $contents = file_get_contents($this->installedJsonPath);

        if ($contents === false) {
            return $this->cache = [];
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->cache = [];
        }

        if (! is_array($decoded)) {
            return $this->cache = [];
        }

        // Composer 2 nests the list under `packages`; Composer 1 wrote a bare
        // array. Both are still found in the wild.
        $packages = $decoded['packages'] ?? $decoded;

        if (! is_array($packages)) {
            return $this->cache = [];
        }

        $found = [];

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $manifest = ExtensionManifest::fromPackage($package);

            if ($manifest === null) {
                continue;
            }

            $found[] = ['manifest' => $manifest, 'prefixes' => $this->prefixes($package)];
        }

        return $this->cache = $found;
    }

    /**
     * @param array<string, mixed> $package
     * @return list<string>
     */
    private function prefixes(array $package): array
    {
        $autoload = $package['autoload'] ?? null;

        if (! is_array($autoload)) {
            return [];
        }

        $psr4 = $autoload['psr-4'] ?? null;

        if (! is_array($psr4)) {
            return [];
        }

        $prefixes = [];

        foreach (array_keys($psr4) as $prefix) {
            if (is_string($prefix) && $prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }
}
