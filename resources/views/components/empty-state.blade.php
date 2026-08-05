{{--
    Nothing here yet — said calmly, with a way forward.

        <x-pandora::empty-state title="Start a conversation">
            Ask the agent something.
            <x-slot:actions>…</x-slot:actions>
        </x-pandora::empty-state>
--}}
@props([
    'title' => null,
    'actions' => null,
    'mark' => true,
])

<div {{ $attributes->class(['pd-empty']) }}>
    <div>
        @if ($mark)
            <div class="pd-empty-mark" aria-hidden="true">
                <img src="{{ \Pandora\Pandora\UI\Assets::url('icons/svg/pandora-icon.svg') }}"
                     alt="" width="48" height="48">
            </div>
        @endif

        @if ($title !== null)
            <p class="pd-empty-title">{{ $title }}</p>
        @endif

        @if (trim($slot) !== '')
            <p>{{ $slot }}</p>
        @endif

        @if ($actions !== null)
            <div class="pd-empty-actions">{{ $actions }}</div>
        @endif
    </div>
</div>
