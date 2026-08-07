{{--
    The Pandora mark.

        <x-pandora::brand />                    full lockup with tagline
        <x-pandora::brand variant="compact" />  lockup without tagline
        <x-pandora::brand variant="lockup" />   sidebar lockup, takes text colour
        <x-pandora::brand variant="icon" />     standalone icon

    Both the light and the dark artwork are placed in the document and CSS
    chooses between them. Nothing waits on JavaScript, so the correct mark is
    painted on the first frame rather than swapped in afterwards.

    A host that wants its own mark publishes this one file; see
    docs/visual-identity.md.
--}}
@props([
    'variant' => 'full',
    'label' => null,
])

@php
    $label ??= config('pandora.ui.brand', 'Pandora') === 'Pandora'
        ? 'Laravel Pandora'
        : config('pandora.ui.brand');

    $assets = \Pandora\UI\Assets::class;
@endphp

@if ($variant === 'icon')
    <span {{ $attributes->class(['pd-brand-icon']) }}>
        <img src="{{ $assets::url('icons/svg/pandora-icon.svg') }}" alt="{{ $label }}" width="32" height="32">
    </span>
@elseif ($variant === 'lockup')
    <span {{ $attributes->class(['pd-brand-lockup']) }} role="img" aria-label="{{ $label }}">
        {!! $assets::inline('logos/sidebar-lockup.svg') !!}
    </span>
@elseif ($variant === 'compact')
    <span {{ $attributes->class(['pd-brand-compact']) }}>
        <img class="pd-on-light" src="{{ $assets::url('logos/laravel-pandora-compact-light.svg') }}"
             alt="{{ $label }}" width="1100" height="240">
        <img class="pd-on-dark" src="{{ $assets::url('logos/laravel-pandora-compact-dark.svg') }}"
             alt="{{ $label }}" width="1100" height="240">
    </span>
@else
    <span {{ $attributes->class(['pd-brand-full']) }}>
        <img class="pd-on-light" src="{{ $assets::url('logos/laravel-pandora-light.svg') }}"
             alt="{{ $label }}" width="1100" height="240">
        <img class="pd-on-dark" src="{{ $assets::url('logos/laravel-pandora-dark.svg') }}"
             alt="{{ $label }}" width="1100" height="240">
    </span>
@endif
