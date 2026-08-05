{{--
    A button, or a link that looks like one.

        <x-pandora::button variant="primary">Send</x-pandora::button>
        <x-pandora::button :href="route('pandora.runs')" variant="ghost" size="sm">View all</x-pandora::button>

    `primary` is violet and means exactly that: the one action this view is for.
    `danger` and `success` stay semantic and are never recoloured to match the
    brand.
--}}
@props([
    'variant' => 'default',
    'size' => null,
    'href' => null,
    'type' => 'button',
    'block' => false,
])

@php
    $classes = ['pd-btn'];

    if ($variant !== 'default') {
        $classes[] = 'pd-btn-'.$variant;
    }

    if ($size === 'sm') {
        $classes[] = 'pd-btn-sm';
    }

    if ($block) {
        $classes[] = 'pd-btn-block';
    }
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
