{{--
    A surface.

        <x-pandora::card title="Recent runs">
            <x-slot:actions>…</x-slot:actions>
            …
        </x-pandora::card>

    Pass `:padded="false"` when the body owns its own padding — a table that
    should reach the card's edges, for instance.
--}}
@props([
    'title' => null,
    'actions' => null,
    'padded' => true,
    'flat' => false,
])

<div {{ $attributes->class(['pd-card', 'pd-card-flat' => $flat]) }}>
    @if ($title !== null || $actions !== null)
        <div class="pd-card-head">
            @if ($title !== null)
                <h2 class="pd-card-title">{{ $title }}</h2>
            @else
                <span></span>
            @endif

            @if ($actions !== null)
                <div class="pd-row">{{ $actions }}</div>
            @endif
        </div>
    @endif

    @if ($padded)
        <div class="pd-card-body">{{ $slot }}</div>
    @else
        {{ $slot }}
    @endif
</div>
