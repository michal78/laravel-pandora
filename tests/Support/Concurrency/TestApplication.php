<?php

declare(strict_types=1);

namespace Pandora\Tests\Support\Concurrency;

use Livewire\LivewireServiceProvider;
use Pandora\PandoraServiceProvider;

/**
 * The providers the suite boots, in one place.
 *
 * `TestCase` needs them and so does every concurrent worker subprocess. The
 * worker originally listed only `PandoraServiceProvider`, which booted fine on
 * a developer machine and died in CI with `Target class [livewire.finder] does
 * not exist` -- Pandora registers Livewire components, so its provider depends
 * on Livewire's having been registered first, and whether that happened by
 * discovery rather than declaration varied by environment.
 *
 * A worker that boots a different application than the test that spawned it is
 * not testing the same thing, and the failure need not be as loud as this one
 * was.
 */
final class TestApplication
{
    /**
     * @return list<class-string>
     */
    public static function providers(): array
    {
        return [
            PandoraServiceProvider::class,
            LivewireServiceProvider::class,
        ];
    }
}
