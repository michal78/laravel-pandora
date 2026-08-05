<?php

declare(strict_types=1);

namespace Pandora\Pandora\UI;

/**
 * Resolves the control center's self-contained stylesheet.
 *
 * The layout must not read the file with `__DIR__`: inside a compiled Blade
 * view `__DIR__` is the host application's compiled-views directory, not the
 * package, so the path only ever resolves during a cache miss on a machine
 * where the two happen to line up. Anchoring here -- in a real class, in the
 * package -- makes it correct whether the view is cached, published, or both.
 */
final class Assets
{
    private static ?string $styles = null;

    public static function styles(): string
    {
        if (self::$styles !== null) {
            return self::$styles;
        }

        $path = dirname(__DIR__, 2).'/resources/views/assets/pandora.css';

        return self::$styles = is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Forget the cached stylesheet. Test seam only.
     */
    public static function flush(): void
    {
        self::$styles = null;
    }
}
