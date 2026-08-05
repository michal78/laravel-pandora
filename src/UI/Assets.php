<?php

declare(strict_types=1);

namespace Pandora\Pandora\UI;

use Illuminate\Support\Facades\Route;

/**
 * Resolves the control center's brand assets and stylesheet.
 *
 * Two rules shape this class.
 *
 * The stylesheet must not be read with `__DIR__` from inside a compiled Blade
 * view: there `__DIR__` is the host application's compiled-views directory, not
 * the package. Anchoring here -- in a real class, in the package -- makes it
 * correct whether the view is cached, published, or both.
 *
 * Brand assets are files, never base64 blobs in markup. A host that has run
 * `vendor:publish --tag=pandora-assets` gets them straight off disk through the
 * web server; a host that has not still gets them, served by a package route.
 * Installing Pandora is never gated on remembering to publish.
 */
final class Assets
{
    /**
     * Where published assets land, relative to the host's public directory.
     */
    public const PUBLIC_DIRECTORY = 'vendor/pandora';

    private static ?string $styles = null;

    /** @var array<string, string> */
    private static array $inlined = [];

    /**
     * The design tokens followed by the control center stylesheet.
     *
     * The token file is the brand kit's own, shipped verbatim: it is the source
     * of truth for colour, radius and shadow, and everything the control center
     * paints derives from it.
     */
    public static function styles(): string
    {
        if (self::$styles !== null) {
            return self::$styles;
        }

        $tokens = self::read(self::path('design-tokens/pandora.css'));
        $sheet = self::read(dirname(__DIR__, 2).'/resources/views/assets/pandora.css');

        return self::$styles = trim($tokens."\n".$sheet);
    }

    /**
     * A URL for a brand asset, preferring the host's published copy.
     *
     * Published files are fingerprinted by modification time so a brand update
     * is not served from a stale browser cache.
     */
    public static function url(string $asset): string
    {
        $asset = self::normalise($asset);
        $published = public_path(self::PUBLIC_DIRECTORY.'/'.$asset);

        if (is_file($published)) {
            return asset(self::PUBLIC_DIRECTORY.'/'.$asset).'?v='.substr((string) filemtime($published), -8);
        }

        if (Route::has('pandora.asset')) {
            return route('pandora.asset', ['path' => $asset]);
        }

        // No published copy and no route -- the UI is disabled, so nothing is
        // rendering this anyway. Point at where a publish would put it.
        return asset(self::PUBLIC_DIRECTORY.'/'.$asset);
    }

    /**
     * The raw markup of a packaged SVG, for the marks drawn in `currentColor`.
     *
     * Inlining is not decoration: `currentColor` inside an `<img>` resolves
     * against nothing, so a lockup that is meant to take the surrounding text
     * colour has to be part of the document to work in both themes.
     */
    public static function inline(string $asset): string
    {
        $asset = self::normalise($asset);

        if (! str_ends_with($asset, '.svg')) {
            return '';
        }

        return self::$inlined[$asset] ??= self::read(self::path($asset));
    }

    /**
     * The absolute path of a packaged asset, or null if it escapes the
     * asset directory or does not exist.
     */
    public static function path(string $asset): ?string
    {
        $root = realpath(self::directory());

        if ($root === false) {
            return null;
        }

        $resolved = realpath($root.'/'.self::normalise($asset));

        if ($resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || ! is_file($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * The packaged asset directory -- the publish source, and the fallback
     * route's document root.
     */
    public static function directory(): string
    {
        return dirname(__DIR__, 2).'/resources/dist';
    }

    /**
     * Forget cached file contents. Test seam only.
     */
    public static function flush(): void
    {
        self::$styles = null;
        self::$inlined = [];
    }

    /**
     * Strip anything that could walk out of the asset directory before the
     * path is ever concatenated. `path()` verifies the result as well; this is
     * the cheap first gate, not the only one.
     */
    private static function normalise(string $asset): string
    {
        $asset = str_replace('\\', '/', $asset);
        $segments = array_filter(
            explode('/', $asset),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
        );

        return implode('/', $segments);
    }

    private static function read(?string $path): string
    {
        return $path !== null && is_file($path) ? (string) file_get_contents($path) : '';
    }
}
