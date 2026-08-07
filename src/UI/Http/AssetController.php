<?php

declare(strict_types=1);

namespace Pandora\UI\Http;

use Pandora\UI\Assets;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the packaged brand assets for hosts that have not published them.
 *
 * These are public brand files -- logos, icons, favicons, design tokens. They
 * carry no application data, so the route sits outside the control center's
 * authorization: a favicon has to load on a login screen too.
 *
 * Publishing to `public/vendor/pandora` bypasses this controller entirely and
 * is what a production deployment should do; this exists so that a fresh
 * install is never a broken-looking one.
 */
final class AssetController
{
    /**
     * Only these types are servable. An extension not on this list cannot be
     * reached even if someone drops a file into the asset directory.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'css' => 'text/css',
        'json' => 'application/json',
        'webmanifest' => 'application/manifest+json',
    ];

    public function __invoke(string $path): BinaryFileResponse
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $resolved = Assets::path($path);

        if ($resolved === null || ! isset(self::TYPES[$extension])) {
            throw new NotFoundHttpException;
        }

        return response()->file($resolved, [
            'Content-Type' => self::TYPES[$extension],
            // Immutable is safe: the URL is fingerprinted by package version,
            // and an unpublished asset only changes when the package does.
            'Cache-Control' => 'public, max-age=31536000',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
