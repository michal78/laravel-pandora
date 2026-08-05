# Visual identity

Pandora ships its own identity so that the control center looks finished the
moment it is installed, and overridable so that it never fights your
application's.

Nothing here requires a build step, a bundler, or a font download.

The identity itself — the brand idea, logo rules, palette and voice — is
documented in [brand-guide.md](brand-guide.md). This page is about how the
package implements it and how you override it.

## What ships

| Path (under `resources/dist`) | What it is |
|---|---|
| `logos/laravel-pandora-{light,dark}.svg` | Full lockup with tagline |
| `logos/laravel-pandora-compact-{light,dark}.svg` | Lockup without tagline |
| `logos/sidebar-lockup.svg` | Navigation lockup, wordmark in `currentColor` |
| `icons/svg/pandora-icon.svg` | Standalone icon |
| `icons/svg/pandora-icon-mono.svg` | Monochrome icon, `currentColor` |
| `icons/png/pandora-icon-*.png` | Raster app icons, 16–1024px |
| `favicons/*` | Favicon set and web manifest |
| `design-tokens/pandora.css` | The design tokens |
| `design-tokens/pandora.tokens.json` | The same tokens, machine readable |

## How assets are served

Two routes to the same files, in this order:

1. **Published**, if the host has published them:

   ```bash
   php artisan vendor:publish --tag=pandora-assets
   ```

   Files land in `public/vendor/pandora` and are served by your web server.
   URLs are fingerprinted by modification time, so a brand update is never
   served from a stale cache. This is what a production deployment should do.

2. **From the package**, if it has not. A route inside the Pandora prefix
   (`/pandora/assets/{path}`) serves them straight from `resources/dist`.

The second exists so that a fresh install is never a broken-looking one.
`Pandora\Pandora\UI\Assets::url()` picks between them; templates never hard-code
a path, and no asset is ever base64-encoded into markup.

The asset route sits outside the control center's middleware on purpose. These
are public brand files with no application data in them, and a favicon has to
resolve on screens the user is not signed in to.

## Design tokens

`design-tokens/pandora.css` is the source of truth for colour, radius and
shadow. It is inlined ahead of the control center stylesheet, which derives
every one of its own `--pd-*` tokens from a `--pandora-*` token:

```css
--pd-accent:       var(--pandora-primary);
--pd-accent-hover: var(--pandora-primary-hover);
--pd-accent-soft:  var(--pandora-primary-soft);
```

Retheme by overriding the `--pandora-*` layer and everything downstream follows.
The control center adds tokens the brand kit deliberately leaves open — the
semantic states (`--pd-success`, `--pd-warning`, `--pd-danger`, `--pd-info`) and
the elevation steps between surfaces.

### Where violet is allowed

The primary violet marks meaning, not decoration. It is used for primary
actions, active navigation, links, the selected conversation, focus rings, and
live or streaming states. Nothing else.

Danger, warning and success keep their own hues in both themes. A failed run is
red whatever the brand colour is.

### Contrast

Every text and control colour meets WCAG AA (4.5:1) against the surface it sits
on, in both themes. Metadata reads quieter through size and weight rather than a
paler grey — anything lighter than `--pandora-text-muted` fails AA on the
secondary canvas. If you retheme, check the same.

## Theming

The theme is an attribute on `<html>`:

```html
<html data-theme="dark" class="dark">
```

Both are written together. The brand token file scopes its dark values to
`.dark`; the component layer keys off `[data-theme="dark"]`. Write one without
the other and half the palette flips.

Resolution happens in a small inline script in `<head>`, before the stylesheet:
an explicit choice in `localStorage`, otherwise `config('pandora.ui.theme')`,
otherwise the OS preference. Because it runs before the first paint, neither the
surfaces nor the logo flash the wrong variant.

Set the default without any JavaScript at all:

```php
// config/pandora.php
'ui' => ['theme' => 'dark'], // light | dark | system
```

## Blade components

```blade
<x-pandora::brand variant="full" />     {{-- lockup with tagline --}}
<x-pandora::brand variant="compact" />  {{-- lockup without tagline --}}
<x-pandora::brand variant="lockup" />   {{-- sidebar lockup, takes text colour --}}
<x-pandora::brand variant="icon" />     {{-- standalone icon --}}

<x-pandora::icon name="pandora-mono" size="20" />
<x-pandora::button variant="primary" size="sm">Send</x-pandora::button>
<x-pandora::card title="Recent runs" :padded="false">…</x-pandora::card>
<x-pandora::badge tone="success">Completed</x-pandora::badge>
<x-pandora::status :state="$run->state" />
<x-pandora::empty-state title="No runs yet">…</x-pandora::empty-state>
```

The brand component places both the light and the dark artwork in the document
and lets CSS choose. That is why the logo is correct on the first frame rather
than swapped in afterwards.

In the sidebar, the lockup shows while the sidebar is expanded and the
standalone icon while it is collapsed — also pure CSS, keyed off
`[data-pd-sidebar="collapsed"]`.

## Overriding the brand safely

### Change the name only

```php
// config/pandora.php
'ui' => ['brand' => 'Acme Agents'],
```

This changes the page title and the accessible label on the mark.

### Replace the mark

Publish the views and edit one file:

```bash
php artisan vendor:publish --tag=pandora-views
```

Then edit `resources/views/vendor/pandora/components/brand.blade.php`. Every
place the mark appears goes through that component, so there is exactly one file
to change. Keep the `variant` values — the layout asks for `lockup` and `icon`
by name.

### Retheme without touching views

Publish the views and add your own custom properties after the stylesheet in
`resources/views/vendor/pandora/layouts/app.blade.php`:

```blade
<style>{!! \Pandora\Pandora\UI\Assets::styles() !!}</style>
<style>
    :root { --pandora-primary: #0F766E; --pandora-primary-hover: #0B5C56; }
    .dark { --pandora-primary: #5EEAD4; }
</style>
```

Override the `--pandora-*` layer, not individual `--pd-*` tokens — the whole
component layer is downstream of it.

### Replace the assets

Publish them, then overwrite the files in `public/vendor/pandora`. Published
copies win over the packaged ones, so no code changes and no republishing.
Keep the filenames.

## Access-denied screen

Pandora does not hijack your error handling. An unauthorized request raises
`AuthorizationException` and your application's 403 handler decides what
happens — that is deliberate, and it means the control center behaves like the
rest of your app.

If you would rather show something branded, the package owns a standalone view
you can point at:

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (AuthorizationException $e, Request $request) {
        if ($request->routeIs('pandora.*')) {
            return response()->view('pandora::errors.denied', [], 403);
        }
    });
})
```

It accepts optional `$title`, `$message` and `$action` variables.

## Typography

Inter where the machine has it, with a native system sans-serif fallback.
Pandora never fetches a font from a third party: a control center should not
make a request to someone else's CDN on every page load.

To use Inter everywhere, install it in your own application — self-host the
files and declare `@font-face` in your layout. Pandora will pick it up, because
`--pd-font` already asks for Inter first.

Weights: 400 body, 500 labels and navigation, 600 headings, 700 the brand name
and primary actions.

## Shape

Standard radius 14px, large cards 20px, buttons 12px. Pills are reserved for
compact status labels, where the shape is doing work.

## Motion

Every animation — the live-state pulse, the streaming cursor, progress,
skeletons, toasts — is disabled under `prefers-reduced-motion: reduce`. A live
state still has to read as live without motion, so an indeterminate progress bar
fills rather than sliding.
