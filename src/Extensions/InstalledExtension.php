<?php

declare(strict_types=1);

namespace Pandora\Extensions;

/**
 * One installed package that claims to extend Pandora, and what it turned out
 * to actually register.
 *
 * `declared` is the manifest's word. `registered` is what the registries say
 * after boot. They are kept apart rather than reconciled, because the innocent
 * cause of a difference (a typo, a renamed tool) and the interesting one (a
 * package registering more than it admits to) look identical from here. Only a
 * person can tell them apart, so both halves are shown to one (ADR-0016).
 */
final readonly class InstalledExtension
{
    /**
     * @param array<string, list<string>> $registered capability type => keys actually registered
     * @param array<string, list<string>> $undeclared capability type => registered but not declared
     * @param array<string, list<string>> $missing capability type => declared but not registered
     */
    public function __construct(
        public ExtensionManifest $manifest,
        public array $registered = [],
        public array $undeclared = [],
        public array $missing = [],
    ) {}

    public function matchesItsManifest(): bool
    {
        return $this->undeclared === [] && $this->missing === [];
    }
}
