<?php

declare(strict_types=1);

namespace Pandora\Extensions;

use Pandora\Channels\ChannelRegistry;
use Pandora\Tools\ToolRegistry;

/**
 * Compares what a package claimed with what it actually registered.
 *
 * The registries are the authority. A manifest that declares a tool the package
 * never registers grants nothing — the tool does not exist, calling it fails at
 * the registry the way any unknown tool does. A package that registers a tool it
 * never declared is likewise not stopped by this class; it is *shown*, which is
 * the point. Enforcement here would mean a manifest could withhold a capability
 * from its own code, and a package author can already do that by not writing the
 * code (ADR-0016, decision 5).
 *
 * Attribution runs off the PSR-4 prefixes in `installed.json`: a registered
 * adapter's class name is matched to the package whose prefix it starts with.
 * That is imperfect — a package can register a class from somewhere else — and
 * it is honest about being a report rather than a boundary.
 */
final class ExtensionInspector
{
    public function __construct(
        private readonly ExtensionDiscovery $discovery,
        private readonly ChannelRegistry $channels,
        private readonly ToolRegistry $tools,
    ) {}

    /**
     * @return list<InstalledExtension>
     */
    public function all(): array
    {
        $registered = $this->registeredByPackage();

        return array_map(
            function (ExtensionManifest $manifest) use ($registered): InstalledExtension {
                $actual = $registered[$manifest->package] ?? [];

                return new InstalledExtension(
                    manifest: $manifest,
                    registered: $actual,
                    undeclared: $this->diff($actual, $this->declaredOf($manifest)),
                    missing: $this->diff($this->declaredOf($manifest), $actual),
                );
            },
            $this->discovery->manifests(),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function declaredOf(ExtensionManifest $manifest): array
    {
        $declared = [];

        foreach (['channels', 'tools'] as $type) {
            $items = $manifest->declares($type);

            if ($items !== []) {
                $declared[$type] = $items;
            }
        }

        return $declared;
    }

    /**
     * @return array<string, array<string, list<string>>> package => type => keys
     */
    private function registeredByPackage(): array
    {
        $byPackage = [];

        foreach ($this->channels->all() as $key => $channel) {
            $package = $this->discovery->packageFor($channel::class);

            if ($package !== null) {
                $byPackage[$package]['channels'][] = $key;
            }
        }

        foreach ($this->tools->all() as $tool) {
            $package = $this->discovery->packageFor($tool::class);

            if ($package !== null) {
                $byPackage[$package]['tools'][] = $tool->name();
            }
        }

        return $byPackage;
    }

    /**
     * @param array<string, list<string>> $left
     * @param array<string, list<string>> $right
     * @return array<string, list<string>>
     */
    private function diff(array $left, array $right): array
    {
        $result = [];

        foreach ($left as $type => $items) {
            $difference = array_values(array_diff($items, $right[$type] ?? []));

            if ($difference !== []) {
                $result[$type] = $difference;
            }
        }

        return $result;
    }
}
