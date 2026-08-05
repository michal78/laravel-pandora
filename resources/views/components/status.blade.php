{{--
    A run's state, rendered as a badge.

        <x-pandora::status :state="$run->state" />

    The enum already knows its own label and tone; this exists so that a run's
    state looks the same everywhere it appears, and so a live run pulses in
    every one of those places.
--}}
@props([
    'state',
])

<x-pandora::badge :tone="$state->tone()" :live="! $state->isTerminal()" {{ $attributes }}>
    {{ $state->label() }}
</x-pandora::badge>
