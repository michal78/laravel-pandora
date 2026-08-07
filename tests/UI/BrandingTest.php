<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Pandora\Pandora\PandoraServiceProvider;
use Pandora\Pandora\Runs\Enums\RunState;
use Pandora\Pandora\Tests\Support\MakesRuns;
use Pandora\Pandora\UI\Assets;

uses(MakesRuns::class);

/**
 * The visual identity, held in place by tests.
 *
 * Branding is easy to break silently: a renamed asset, a lost publish tag, or a
 * theme toggle that stops writing the class the tokens key off. None of that
 * fails a functional test, and all of it is visible to every user of the
 * control center.
 */
beforeEach(function (): void {
    Assets::flush();
    $this->user = $this->actingAsUser();
});

// ------------------------------------------------------------------ assets

it('ships every brand asset the layout and docs reference', function (string $asset): void {
    expect(Assets::path($asset))->not->toBeNull();
})->with([
    'logos/laravel-pandora-light.svg',
    'logos/laravel-pandora-dark.svg',
    'logos/laravel-pandora-compact-light.svg',
    'logos/laravel-pandora-compact-dark.svg',
    'logos/sidebar-lockup.svg',
    'icons/svg/pandora-icon.svg',
    'icons/svg/pandora-icon-mono.svg',
    'favicons/favicon.ico',
    'favicons/favicon-32x32.png',
    'favicons/apple-touch-icon.png',
    'favicons/android-chrome-192x192.png',
    'favicons/android-chrome-512x512.png',
    'favicons/site.webmanifest',
    'design-tokens/pandora.css',
    'design-tokens/pandora.tokens.json',
]);

it('serves a packaged asset over the fallback route when nothing is published', function (): void {
    $response = $this->get(Assets::url('icons/svg/pandora-icon.svg'));

    $response->assertOk()->assertHeader('content-type', 'image/svg+xml');

    // A file response streams; the assertion has to be about which file.
    expect($response->baseResponse->getFile()->getPathname())
        ->toBe(Assets::path('icons/svg/pandora-icon.svg'));
});

it('serves brand assets to a guest, because a favicon loads before sign-in', function (): void {
    auth()->guard()->logout();

    $this->get(Assets::url('favicons/favicon.ico'))->assertOk();
});

it('refuses to serve anything outside the packaged asset directory', function (string $path): void {
    $this->get('/pandora/assets/'.$path)->assertNotFound();
})->with([
    'traversal' => '../../config/pandora.php',
    'encoded traversal' => '..%2F..%2Fcomposer.json',
    'unlisted type' => 'design-tokens/../../../composer.json',
    'missing file' => 'logos/does-not-exist.svg',
]);

it('prefers the host published copy and fingerprints it', function (): void {
    $published = public_path(Assets::PUBLIC_DIRECTORY.'/icons/svg/pandora-icon.svg');
    File::ensureDirectoryExists(dirname($published));
    File::copy((string) Assets::path('icons/svg/pandora-icon.svg'), $published);

    try {
        expect(Assets::url('icons/svg/pandora-icon.svg'))
            ->toContain(Assets::PUBLIC_DIRECTORY.'/icons/svg/pandora-icon.svg')
            ->toContain('?v=');
    } finally {
        File::deleteDirectory(public_path(Assets::PUBLIC_DIRECTORY));
    }
});

it('registers the asset publish tag so a host can serve them from disk', function (): void {
    $paths = ServiceProvider::pathsToPublish(
        PandoraServiceProvider::class,
        'pandora-assets',
    );

    expect($paths)->toHaveKey(Assets::directory())
        ->and($paths[Assets::directory()])->toBe(public_path(Assets::PUBLIC_DIRECTORY));
});

// ------------------------------------------------------------------ tokens

it('builds the stylesheet on the brand kit design tokens', function (): void {
    // The token file is the source of truth. If it stops being inlined, every
    // `--pd-*` alias below it resolves to nothing and the UI renders unstyled.
    expect(Assets::styles())
        ->toContain('--pandora-primary: #5B46D9')
        ->toContain('--pandora-primary: #8F7DF0')
        ->toMatch('/--pd-accent:\s+var\(--pandora-primary\)/');
});

it('keeps semantic states distinct from the brand violet', function (): void {
    $styles = Assets::styles();

    foreach (['--pd-danger:', '--pd-success:', '--pd-warning:'] as $token) {
        expect($styles)->toContain($token);
        expect($styles)->not->toContain($token.' var(--pandora-primary)');
    }
});

it('respects prefers-reduced-motion', function (): void {
    expect(Assets::styles())->toContain('@media (prefers-reduced-motion: reduce)');
});

// ------------------------------------------------------------------- brand

it('renders each brand variant from a real asset, never an inline blob', function (string $variant, string $needle): void {
    $html = Blade::render('<x-pandora::brand variant="'.$variant.'" />');

    expect($html)->toContain($needle)->not->toContain('data:image');
})->with([
    'full' => ['full', 'laravel-pandora-light.svg'],
    'compact' => ['compact', 'laravel-pandora-compact-light.svg'],
    'icon' => ['icon', 'pandora-icon.svg'],
]);

it('inlines the sidebar lockup so its wordmark takes the surrounding colour', function (): void {
    $html = Blade::render('<x-pandora::brand variant="lockup" />');

    expect($html)
        ->toContain('<svg')
        ->toContain('currentColor')
        ->toContain('aria-label="Laravel Pandora"');
});

it('puts both light and dark artwork in the document so CSS can switch without a script', function (): void {
    $html = Blade::render('<x-pandora::brand variant="compact" />');

    expect($html)
        ->toContain('laravel-pandora-compact-light.svg')
        ->toContain('laravel-pandora-compact-dark.svg')
        ->toContain('pd-on-light')
        ->toContain('pd-on-dark');

    expect(Assets::styles())->toContain('[data-theme="dark"] .pd-on-light { display: none; }');
});

// ------------------------------------------------------------------ layout

it('brands the control center layout', function (): void {
    $response = $this->get('/pandora');

    $response->assertOk()
        // The sidebar lockup is inlined, so it is the artwork itself that has
        // to be present -- not a link to it.
        ->assertSee('pd-brand-lockup', false)
        ->assertSee('<text x="112"', false)
        ->assertSee('favicons/favicon.ico', false)
        ->assertSee('rel="apple-touch-icon"', false)
        ->assertSee('rel="manifest"', false)
        ->assertSee('content="#5B46D9"', false);
});

it('shows the lockup expanded and the standalone icon collapsed', function (): void {
    $this->get('/pandora')
        ->assertOk()
        ->assertSee('pd-brand-expanded', false)
        ->assertSee('pd-brand-collapsed', false);

    expect(Assets::styles())
        ->toContain('[data-pd-sidebar="collapsed"] .pd-brand-collapsed { display: block; }');
});

it('resolves the theme before the stylesheet, so the first paint is already correct', function (): void {
    $html = $this->get('/pandora')->assertOk()->getContent();

    $script = strpos((string) $html, "localStorage.getItem('pandora-theme')");
    $stylesheet = strpos((string) $html, '--pandora-primary');
    $body = strpos((string) $html, '<body>');

    expect($script)->not->toBeFalse()
        ->and($script)->toBeLessThan((int) $stylesheet)
        ->and($script)->toBeLessThan((int) $body);
});

it('restores the theme after a wire:navigate, which overwrites the html attributes', function (): void {
    // Livewire copies the incoming page's <html> attributes over the current
    // ones on navigation, so a resolved dark theme reverts to the configured
    // default and a collapsed sidebar springs open. The head script is not
    // re-run — an unchanged head is kept rather than replaced — so the page
    // has to re-apply the stored state itself.
    $html = (string) $this->get('/pandora')->assertOk()->getContent();

    expect($html)->toContain("document.addEventListener('livewire:navigated', restore)");
});

it('keeps the dark class and the theme attribute in step', function (): void {
    // The brand token file scopes its dark values to `.dark`; the component
    // layer keys off `[data-theme="dark"]`. Both must be written together or
    // half the palette flips and the other half does not.
    $html = (string) $this->get('/pandora')->assertOk()->getContent();

    expect($html)->toContain("root.classList.toggle('dark', resolved === 'dark')");

    config()->set('pandora.ui.theme', 'dark');

    expect((string) $this->get('/pandora')->getContent())
        ->toContain('data-theme="dark"')
        ->toContain('class="dark"');
});

it('renders the branded access-denied screen without the control center chrome', function (): void {
    $html = view('pandora::errors.denied')->render();

    expect($html)
        ->toContain('pd-gate')
        ->toContain('laravel-pandora-compact-light.svg')
        ->toContain('Access denied')
        // Styled by the same sheet, but with none of the control center's
        // navigation rendered into it.
        ->not->toContain('<nav');
});

// -------------------------------------------------------------- components

it('renders a primary button as the one violet action', function (): void {
    expect(Blade::render('<x-pandora::button variant="primary">Send</x-pandora::button>'))
        ->toContain('pd-btn pd-btn-primary')
        ->toContain('Send');
});

it('renders a button as a link when given an href', function (): void {
    expect(Blade::render('<x-pandora::button href="/pandora/runs" size="sm">All</x-pandora::button>'))
        ->toContain('<a href="/pandora/runs"')
        ->toContain('pd-btn-sm');
});

it('omits the disabled attribute when a button is enabled', function (): void {
    expect(Blade::render('<x-pandora::button :disabled="false">Send</x-pandora::button>'))
        ->not->toContain('disabled');

    expect(Blade::render('<x-pandora::button :disabled="true">Send</x-pandora::button>'))
        ->toContain('disabled');
});

it('keeps badge tones semantic', function (): void {
    expect(Blade::render('<x-pandora::badge tone="danger">Failed</x-pandora::badge>'))
        ->toContain('pd-badge-danger');

    expect(Blade::render('<x-pandora::badge tone="accent" live>Streaming</x-pandora::badge>'))
        ->toContain('pd-badge-accent')
        ->toContain('is-live');
});

it('renders a run state through the status component', function (): void {
    $run = $this->makeRun(['state' => RunState::Completed]);

    expect(Blade::render('<x-pandora::status :state="$state" />', ['state' => $run->state]))
        ->toContain('pd-badge-success')
        ->toContain($run->state->label())
        ->not->toContain('is-live');
});

it('marks an unfinished run as live', function (): void {
    expect(Blade::render('<x-pandora::status :state="$state" />', [
        'state' => RunState::Running,
    ]))->toContain('is-live');
});

it('renders a card with a title, actions and body', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-pandora::card title="Recent runs">
            <x-slot:actions>Action</x-slot:actions>
            Body
        </x-pandora::card>
    BLADE);

    expect($html)
        ->toContain('pd-card-title')
        ->toContain('Recent runs')
        ->toContain('Action')
        ->toContain('Body');
});

it('renders an empty state with the brand mark', function (): void {
    expect(Blade::render('<x-pandora::empty-state title="Nothing yet">Add one.</x-pandora::empty-state>'))
        ->toContain('pd-empty-title')
        ->toContain('Nothing yet')
        ->toContain('pandora-icon.svg');
});
