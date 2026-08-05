{{--
    A branded access-denied screen for Pandora's own routes.

    Pandora does not hijack your application's error handling: an unauthorized
    request still raises `AuthorizationException` and your 403 handler decides
    what happens. This view exists for hosts that would rather show something
    that looks like the control center than a generic error page. Point your
    handler at `pandora::errors.denied` for requests inside the Pandora prefix;
    docs/visual-identity.md shows how.
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
    <title>{{ $title ?? 'Access denied' }} &middot; {{ config('pandora.ui.brand', 'Pandora') }}</title>

    <link rel="icon" href="{{ $assets::url('favicons/favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ $assets::url('icons/svg/pandora-icon.svg') }}">
    <meta name="theme-color" content="#5B46D9">

    <script>
        (function () {
            const root = document.documentElement;
            const stored = (() => { try { return localStorage.getItem('pandora-theme'); } catch (e) { return null; } })();
            const theme = stored ?? root.dataset.theme;
            const resolved = theme === 'system' || !theme
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : theme;
            root.dataset.theme = resolved;
            root.classList.toggle('dark', resolved === 'dark');
        })();
    </script>

    <style>{!! $assets::styles() !!}</style>
</head>
<body>
    <div class="pd-gate">
        <div class="pd-card pd-gate-card">
            <x-pandora::brand variant="compact" />

            <h1 class="pd-gate-title">{{ $title ?? 'Access denied' }}</h1>

            <p class="pd-muted">
                {{ $message ?? 'Your account does not have permission to use the Pandora control center.' }}
            </p>

            @isset($action)
                <div class="pd-empty-actions">{{ $action }}</div>
            @endisset
        </div>
    </div>
</body>
</html>
