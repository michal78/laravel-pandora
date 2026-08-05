{{--
    A compact status label.

        <x-pandora::badge tone="success">Completed</x-pandora::badge>
        <x-pandora::badge tone="accent" live>Streaming</x-pandora::badge>

    Tones stay semantic. `accent` is for live and selected states only — a
    failure is red whatever the brand colour is.
--}}
@props([
    'tone' => 'muted',
    'live' => false,
])

<span {{ $attributes->class(['pd-badge', 'pd-badge-'.$tone, 'is-live' => (bool) $live]) }}>
    {{ $slot }}
</span>
