<?php

declare(strict_types=1);

namespace Pandora\Extensions;

/**
 * What a package says about itself, cleaned up.
 *
 * Every string here was written by somebody else and is rendered on an
 * authenticated operator's page, so it arrives through `fromArray()` bounded,
 * type-checked and scheme-restricted. A manifest is markup a third party wrote,
 * arriving on an admin screen; treating it as trusted metadata because it came
 * from `composer.json` is the same mistake as treating an MCP tool description
 * as documentation (ADR-0014).
 *
 * And it is a *description*, never a grant. `provides` says what the package
 * claims to register. Nothing is enabled, permitted or exposed because a
 * manifest said so — the registries are the authority, and the difference
 * between the two is shown to a human rather than resolved by one of them
 * winning (ADR-0016).
 */
final readonly class ExtensionManifest
{
    private const MAX_NAME = 100;

    private const MAX_DESCRIPTION = 500;

    private const MAX_ITEMS = 50;

    private const MAX_ITEM = 100;

    /**
     * @param array<string, list<string>> $provides capability type => declared keys
     * @param array<string, string> $requires
     */
    private function __construct(
        public string $package,
        public string $version,
        public string $name,
        public ?string $description,
        public array $provides,
        public array $requires,
        public ?string $documentation,
    ) {}

    /**
     * Build from one `installed.json` entry.
     *
     * Returns null when the package declares no `extra.pandora` block, which is
     * the case for the overwhelming majority of a vendor directory. A package
     * that has not claimed to be a Pandora extension is not one.
     *
     * @param array<string, mixed> $package
     */
    public static function fromPackage(array $package): ?self
    {
        $name = is_string($package['name'] ?? null) ? $package['name'] : null;

        if ($name === null) {
            return null;
        }

        $extra = $package['extra'] ?? null;

        if (! is_array($extra)) {
            return null;
        }

        $manifest = $extra['pandora'] ?? null;

        if (! is_array($manifest)) {
            return null;
        }

        return new self(
            package: self::bounded($name, self::MAX_ITEM) ?? '',
            version: self::bounded(self::stringOrNull($package['version'] ?? null), 50) ?? 'unknown',
            // A manifest with no name falls back to the package name, which is
            // never absent and is what an operator would search for anyway.
            name: self::bounded(self::stringOrNull($manifest['name'] ?? null), self::MAX_NAME) ?? $name,
            description: self::bounded(self::stringOrNull($manifest['description'] ?? null), self::MAX_DESCRIPTION),
            provides: self::provides($manifest['provides'] ?? null),
            requires: self::requires($manifest['requires'] ?? null),
            documentation: self::url($manifest['documentation'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    public function declares(string $type): array
    {
        return $this->provides[$type] ?? [];
    }

    /**
     * @param mixed $value
     * @return array<string, list<string>>
     */
    private static function provides(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $provides = [];

        foreach ($value as $type => $items) {
            if (! is_string($type) || ! is_array($items)) {
                continue;
            }

            $clean = [];

            foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
                $bounded = self::bounded(self::stringOrNull($item), self::MAX_ITEM);

                if ($bounded !== null && $bounded !== '') {
                    $clean[] = $bounded;
                }
            }

            if ($clean !== []) {
                $provides[self::bounded($type, 50) ?? ''] = $clean;
            }
        }

        return $provides;
    }

    /**
     * @return array<string, string>
     */
    private static function requires(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $requires = [];

        foreach (array_slice($value, 0, self::MAX_ITEMS, true) as $key => $constraint) {
            $constraint = self::bounded(self::stringOrNull($constraint), self::MAX_ITEM);

            if (is_string($key) && $constraint !== null) {
                $requires[self::bounded($key, self::MAX_ITEM) ?? ''] = $constraint;
            }
        }

        return $requires;
    }

    /**
     * A link an operator can click, or nothing.
     *
     * Scheme-restricted because this ends up in an `href` on an authenticated
     * admin page, and `javascript:` in an href is the oldest trick there is.
     */
    private static function url(mixed $value): ?string
    {
        $url = self::bounded(self::stringOrNull($value), 2048);

        if ($url === null) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function bounded(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        // Control characters strip out rather than escape: they have no place
        // in a name, and a terminal rendering `pandora:extension:list` is a
        // second surface with its own escape sequences.
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return mb_substr($clean, 0, $length);
    }
}
