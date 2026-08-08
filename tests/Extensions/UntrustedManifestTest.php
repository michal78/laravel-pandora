<?php

declare(strict_types=1);

use Pandora\Extensions\ExtensionManifest;

/**
 * Phase 8, criterion 24 — a manifest is markup somebody else wrote, arriving on
 * an authenticated admin page.
 *
 * The same reasoning as an MCP tool description (ADR-0014): it looks like
 * metadata, it comes from a file that feels like configuration, and it is
 * written by a third party and rendered to an operator. Bounded on the way in,
 * escaped on the way out, and a `documentation` link restricted to schemes that
 * are actually links — `javascript:` in an `href` is the oldest trick there is.
 */
function manifestFor(array $pandora): ?ExtensionManifest
{
    return ExtensionManifest::fromPackage([
        'name' => 'vendor/hostile',
        'version' => '1.0.0',
        'extra' => ['pandora' => $pandora],
    ]);
}

it('bounds a name and a description a package can make arbitrarily long', function (): void {
    $manifest = manifestFor([
        'name' => str_repeat('A', 5000),
        'description' => str_repeat('B', 50000),
    ]);

    expect(mb_strlen($manifest->name))->toBe(100)
        ->and(mb_strlen((string) $manifest->description))->toBe(500);
});

it('strips control characters, which a terminal would interpret', function (): void {
    // `pandora:extension:list` is a second rendering surface with its own escape
    // sequences, and a name containing them could redraw somebody's console.
    $manifest = manifestFor(['name' => "Slack\x1b[2J\x07 extension\x00"]);

    expect($manifest->name)->toBe('Slack[2J extension');
});

it('refuses a documentation link that is not http or https', function (): void {
    foreach (['javascript:alert(1)', 'data:text/html,<script>', 'file:///etc/passwd', 'not a url'] as $url) {
        expect(manifestFor(['documentation' => $url])->documentation)->toBeNull();
    }

    expect(manifestFor(['documentation' => 'https://example.test/docs'])->documentation)
        ->toBe('https://example.test/docs');
});

it('bounds how many capabilities a package may declare', function (): void {
    $manifest = manifestFor([
        'provides' => ['tools' => array_map(static fn (int $i): string => "tool_{$i}", range(1, 500))],
    ]);

    expect($manifest->declares('tools'))->toHaveCount(50);
});

it('ignores capability entries that are not strings', function (): void {
    $manifest = manifestFor([
        'provides' => ['tools' => ['good_tool', ['nested' => 'array'], 42, null, '']],
    ]);

    expect($manifest->declares('tools'))->toBe(['good_tool']);
});

it('falls back to the package name when the manifest names nothing', function (): void {
    expect(manifestFor([])->name)->toBe('vendor/hostile');
});

it('survives every field being the wrong type', function (): void {
    $manifest = manifestFor([
        'name' => ['not', 'a', 'string'],
        'description' => 42,
        'provides' => 'everything',
        'requires' => true,
        'documentation' => ['https://example.test'],
    ]);

    expect($manifest)->not->toBeNull()
        ->and($manifest->name)->toBe('vendor/hostile')
        ->and($manifest->description)->toBeNull()
        ->and($manifest->provides)->toBe([])
        ->and($manifest->requires)->toBe([])
        ->and($manifest->documentation)->toBeNull();
});
