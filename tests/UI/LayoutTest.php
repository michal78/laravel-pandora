<?php

declare(strict_types=1);

use Pandora\Tests\Support\MakesRuns;
use Pandora\UI\Assets;

uses(MakesRuns::class);

/**
 * Full-page requests, not isolated components.
 *
 * `Livewire::test()` renders a component without its layout, so every layout
 * defect passed the suite and failed in a browser. The control center's
 * stylesheet was read with `__DIR__` from inside a compiled Blade view, which
 * resolves to the host's compiled-views directory -- a 500 on every page of a
 * real installation. These tests go through the router.
 */
beforeEach(function (): void {
    $this->user = $this->actingAsUser();
});

it('resolves the stylesheet from the package, not the compiled view path', function (): void {
    expect(Assets::styles())
        ->not->toBe('')
        ->toContain('--pd-');
});

it('serves every control center page as a complete HTML document', function (string $path): void {
    $response = $this->get($path);

    $response->assertOk()
        ->assertSee('<!DOCTYPE html>', false)
        ->assertSee('<style>', false);
})->with([
    'dashboard' => '/pandora',
    'chat' => '/pandora/chat',
    'runs' => '/pandora/runs',
]);

it('serves a run trace page as a complete HTML document', function (): void {
    $run = $this->makeRun();

    $this->get('/pandora/runs/'.$run->getKey())
        ->assertOk()
        ->assertSee('<!DOCTYPE html>', false);
});

it('denies a guest the control center on its own, without host middleware', function (): void {
    auth()->guard()->logout();

    // A host application adds `auth` middleware and the guest is redirected to
    // a login screen. The package must not depend on that: authorization is
    // enforced inside the component, so with no host middleware at all the
    // answer is still a refusal rather than a rendered page.
    $this->get('/pandora')->assertForbidden();
});

it('never leaves a page on the framework default paginator', function (): void {
    // The class of defect, not one instance of it. Laravel's default
    // pagination view is written for Tailwind, and a package that ships its
    // own CSS cannot render it: both of its blocks appear at once, because
    // the utilities that hide one do not exist, and its inline SVG chevrons
    // are sized entirely by those same utilities, so they fill the page.
    //
    // Found in the browser on /runs, where the chevron was taller than the
    // viewport and the page numbers sat two screens below the table.
    $views = glob(__DIR__.'/../../resources/views/livewire/*.blade.php') ?: [];

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        $source = (string) file_get_contents($view);

        // `->links()` with no argument takes whatever the framework defaults
        // to, which is the one view this design system cannot style.
        expect($source)->not->toContain('->links()', basename($view));
    }
});
