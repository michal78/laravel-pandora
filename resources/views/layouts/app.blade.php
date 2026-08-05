{{--
    Pandora control center layout.

    The design system is deliberately self-contained: a single stylesheet of
    custom properties, no build step, and no dependency on the host
    application's CSS. A package that required you to run its bundler before
    its UI worked would not be worth installing.

    Light and dark both derive from the same token set, so a new surface picks
    up both automatically.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ config('pandora.ui.theme', 'system') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Pandora' }} &middot; {{ config('pandora.ui.brand', 'Pandora') }}</title>

    <style>{!! file_get_contents(__DIR__ . '/../assets/pandora.css') !!}</style>

    @livewireStyles
</head>
<body>
    <div class="pd-shell">
        <aside class="pd-sidebar" id="pd-sidebar">
            <div class="pd-brand">
                <span class="pd-brand-mark" aria-hidden="true"></span>
                <span class="pd-brand-name">{{ config('pandora.ui.brand', 'Pandora') }}</span>
            </div>

            <nav class="pd-nav" aria-label="Main">
                @php
                    $nav = [
                        ['route' => 'pandora.dashboard', 'label' => 'Dashboard', 'glyph' => '◈'],
                        ['route' => 'pandora.chat',      'label' => 'Chat',      'glyph' => '◑'],
                        ['route' => 'pandora.runs',      'label' => 'Runs',      'glyph' => '◇'],
                    ];
                @endphp

                @foreach ($nav as $item)
                    <a href="{{ route($item['route']) }}"
                       class="pd-nav-link {{ request()->routeIs($item['route'] . '*') ? 'is-active' : '' }}"
                       @if (request()->routeIs($item['route'] . '*')) aria-current="page" @endif>
                        <span class="pd-nav-glyph" aria-hidden="true">{{ $item['glyph'] }}</span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="pd-sidebar-foot">
                <button type="button" class="pd-theme-toggle" data-pd-theme-toggle>
                    <span class="pd-theme-icon" aria-hidden="true">◐</span>
                    <span>Theme</span>
                </button>
                <p class="pd-version">Pandora {{ app(\Pandora\Pandora\Pandora::class)->version() }}</p>
            </div>
        </aside>

        <div class="pd-main">
            <header class="pd-topbar">
                <button type="button" class="pd-menu-button" data-pd-sidebar-toggle aria-label="Toggle navigation">
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
        // Theme: respect an explicit choice, otherwise follow the OS. Stored
        // locally so it survives navigation without a round trip.
        (function () {
            const root = document.documentElement;
            const stored = localStorage.getItem('pandora-theme');

            const apply = (theme) => {
                root.dataset.theme = theme === 'system'
                    ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                    : theme;
            };

            apply(stored ?? root.dataset.theme ?? 'system');

            document.querySelector('[data-pd-theme-toggle]')?.addEventListener('click', () => {
                const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
                localStorage.setItem('pandora-theme', next);
                apply(next);
            });

            document.querySelector('[data-pd-sidebar-toggle]')?.addEventListener('click', () => {
                document.getElementById('pd-sidebar')?.classList.toggle('is-open');
            });
        })();
    </script>

    @stack('scripts')
</body>
</html>
