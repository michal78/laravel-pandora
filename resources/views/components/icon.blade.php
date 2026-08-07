{{--
    A packaged icon.

        <x-pandora::icon name="pandora-mono" size="20" />

    The monochrome variant is drawn in `currentColor`, so it is inlined rather
    than linked: an `<img>` has no surrounding colour to inherit.
--}}
@props([
    'name' => 'pandora',
    'size' => 20,
    'label' => null,
])

@php
    $assets = \Pandora\UI\Assets::class;
    $path = 'icons/svg/'.($name === 'pandora-mono' ? 'pandora-icon-mono.svg' : 'pandora-icon.svg');
@endphp

<span {{ $attributes->class(['pd-icon'])->merge([
    'style' => 'display:inline-block;width:'.$size.'px;height:'.$size.'px;line-height:0',
]) }}
    @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>
    @if ($name === 'pandora-mono')
        {!! $assets::inline('icons/svg/pandora-icon-mono.svg') !!}
    @else
        <img src="{{ $assets::url($path) }}" alt="" width="{{ $size }}" height="{{ $size }}">
    @endif
</span>
