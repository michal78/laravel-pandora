{{--
    Pandora control center layout.

    The design system is deliberately self-contained: the brand kit's design
    tokens plus a single stylesheet built on them, no build step, and no
    dependency on the host application's CSS. A package that required you to run
    its bundler before its UI worked would not be worth installing.

    Light and dark both derive from the same token set, so a new surface picks
    up both automatically. Brand artwork is served as files -- published to
    `public/vendor/pandora` if the host has published it, and straight from the
    package if not.
--}}
@php
    $assets = \Pandora\Pandora\UI\Assets::class;
    $configuredTheme = config('pandora.ui.theme', 'system');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ $configuredTheme }}"
      @class(['dark' => $configuredTheme === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Pandora' }} &middot; {{ config('pandora.ui.brand', 'Pandora') }}</title>

    <link rel="icon" href="{{ $assets::url('favicons/favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ $assets::url('icons/svg/pandora-icon.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $assets::url('favicons/favicon-32x32.png') }}">
    <link rel="apple-touch-icon" href="{{ $assets::url('favicons/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ $assets::url('favicons/site.webmanifest') }}">
    <meta name="theme-color" content="#5B46D9">

    {{--
        Theme and sidebar state are resolved in the head, before the first
        paint. Doing it after the body would show the wrong logo and the wrong
        surfaces for a frame -- the flash this ordering exists to prevent.
    --}}
    <script>
        (function () {
            const root = document.documentElement;

            const resolve = (theme) => theme === 'system' || !theme
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : theme;

            const apply = (theme) => {
                const resolved = resolve(theme);
                root.dataset.theme = resolved;
                root.classList.toggle('dark', resolved === 'dark');
            };

            const restore = () => {
                try {
                    apply(localStorage.getItem('pandora-theme') ?? root.dataset.theme);
                    root.dataset.pdSidebar = localStorage.getItem('pandora-sidebar') ?? 'expanded';
                } catch (e) {
                    // Storage can be denied outright. The server-rendered
                    // default still renders a usable, correctly themed page.
                    apply(root.dataset.theme);
                }
            };

            restore();

            // `wire:navigate` copies the incoming page's <html> attributes over
            // this one's, which throws away the resolved theme and the sidebar
            // state and leaves the configured default in their place -- a dark
            // page turning light on the way to a subpage. This script is not
            // re-run on navigation, because an unchanged head is kept rather
            // than replaced, so the restore has to be asked for by hand.
            document.addEventListener('livewire:navigated', restore);
        })();
    </script>

    <style>{!! $assets::styles() !!}</style>

    @livewireStyles
</head>
<body>
    <div class="pd-shell">
        <aside class="pd-sidebar" id="pd-sidebar">
            <a href="{{ route('pandora.dashboard') }}" class="pd-brand">
                <x-pandora::brand variant="lockup" class="pd-brand-expanded" />
                <x-pandora::brand variant="icon" class="pd-brand-collapsed" />
            </a>

            <nav class="pd-nav" aria-label="Main">
                @php
                    // `ability` is what the page itself checks. Filtering here
                    // is not the boundary -- every page authorizes on mount --
                    // but a sidebar offering links that answer 403 teaches
                    // people to ignore authorization errors.
                    $nav = collect([
                        ['route' => 'pandora.dashboard', 'label' => 'Dashboard', 'glyph' => '◈', 'ability' => 'access'],
                        ['route' => 'pandora.chat',      'label' => 'Chat',      'glyph' => '◑', 'ability' => 'chat'],
                        ['route' => 'pandora.agents',    'label' => 'Agents',    'glyph' => '◆', 'ability' => 'access'],
                        ['route' => 'pandora.automations', 'label' => 'Automations', 'glyph' => '◐', 'ability' => 'access'],
                        ['route' => 'pandora.runs',      'label' => 'Runs',      'glyph' => '◇', 'ability' => 'access'],
                        ['route' => 'pandora.tools',     'label' => 'Tools',     'glyph' => '◧', 'ability' => 'access'],
                        ['route' => 'pandora.memory',    'label' => 'Memory',    'glyph' => '◎', 'ability' => 'access'],
                        ['route' => 'pandora.workspaces', 'label' => 'Workspaces', 'glyph' => '▤', 'ability' => 'workspaces.access',
                         'soon' => \Pandora\Pandora\UI\Feature::disabled('workspaces')],
                        ['route' => 'pandora.approvals', 'label' => 'Approvals', 'glyph' => '◉', 'ability' => 'access'],
                        ['route' => 'pandora.providers', 'label' => 'Providers', 'glyph' => '◍', 'ability' => 'access'],
                        ['route' => 'pandora.usage',     'label' => 'Usage',     'glyph' => '◫', 'ability' => 'usage.view'],
                    ])->filter(fn (array $item): bool => \Pandora\Pandora\UI\PandoraGate::allows($item['ability']));
                @endphp

                @foreach ($nav as $item)
                    @if ($item['soon'] ?? false)
                        {{-- Shown, so the feature is not a surprise later, and
                             inert, so it cannot be mistaken for one that works. --}}
                        <span class="pd-nav-link is-soon" title="{{ $item['label'] }} — coming soon"
                              aria-disabled="true">
                            <span class="pd-nav-glyph" aria-hidden="true">{{ $item['glyph'] }}</span>
                            <span>{{ $item['label'] }}</span>
                            <span class="pd-nav-soon">Coming soon</span>
                        </span>

                        @continue
                    @endif

                    <a href="{{ route($item['route']) }}"
                       class="pd-nav-link {{ request()->routeIs($item['route'] . '*') ? 'is-active' : '' }}"
                       title="{{ $item['label'] }}"
                       @if (request()->routeIs($item['route'] . '*')) aria-current="page" @endif>
                        <span class="pd-nav-glyph" aria-hidden="true">{{ $item['glyph'] }}</span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="pd-sidebar-foot">
                <button type="button" class="pd-sidebar-button" data-pd-theme-toggle
                        aria-label="Switch between light and dark theme">
                    <span class="pd-theme-icon" aria-hidden="true">◐</span>
                    <span>Theme</span>
                </button>

                <button type="button" class="pd-sidebar-button pd-collapse-button" data-pd-collapse-toggle
                        aria-label="Collapse or expand the sidebar" aria-controls="pd-sidebar">
                    <span class="pd-theme-icon" aria-hidden="true">⇤</span>
                    <span>Collapse</span>
                </button>

                <p class="pd-version">Pandora {{ app(\Pandora\Pandora\Pandora::class)->version() }}</p>
            </div>
        </aside>

        <div class="pd-main">
            <header class="pd-topbar">
                <button type="button" class="pd-icon-button pd-menu-button" data-pd-sidebar-toggle
                        aria-label="Toggle navigation" aria-controls="pd-sidebar">
                    <span aria-hidden="true">☰</span>
                </button>
                <h1 class="pd-page-title">{{ $title ?? 'Pandora' }}</h1>
            </header>

            <main class="pd-content">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts

    <script>
        // Interaction only -- the state these toggles write was already applied
        // in the head, so nothing here affects the first paint.
        (function () {
            const root = document.documentElement;

            const remember = (key, value) => {
                try { localStorage.setItem(key, value); } catch (e) { /* storage denied */ }
            };

            document.querySelector('[data-pd-theme-toggle]')?.addEventListener('click', () => {
                const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
                root.dataset.theme = next;
                root.classList.toggle('dark', next === 'dark');
                remember('pandora-theme', next);
            });

            document.querySelector('[data-pd-collapse-toggle]')?.addEventListener('click', () => {
                const next = root.dataset.pdSidebar === 'collapsed' ? 'expanded' : 'collapsed';
                root.dataset.pdSidebar = next;
                remember('pandora-sidebar', next);
            });

            document.querySelector('[data-pd-sidebar-toggle]')?.addEventListener('click', () => {
                document.getElementById('pd-sidebar')?.classList.toggle('is-open');
            });
        })();
    </script>

    @stack('scripts')
</body>
</html>
