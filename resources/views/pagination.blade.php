{{--
    Pandora's paginator.

    Laravel's default pagination view is written for Tailwind, and Pandora
    ships no Tailwind. Rendered here, its two blocks -- the compact one meant
    for small screens and the numbered one meant for large -- both appear at
    once, because the utility classes that hide one of them do not exist. Its
    heroicon chevrons are inline SVGs sized entirely by those same classes, so
    with no CSS to bound them they render at whatever the viewport allows.

    So this view is not a restyling. It is the only one that can be correct in
    a package that brings its own CSS: one block, no SVG, everything sized by
    tokens this design system actually defines.
--}}
@if ($paginator->hasPages())
    <nav class="pd-pagination" role="navigation" aria-label="Pagination">
        <p class="pd-pagination-count">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
            of {{ $paginator->total() }}
        </p>

        <div class="pd-pagination-pages">
            @if ($paginator->onFirstPage())
                <span class="pd-btn pd-btn-sm" aria-disabled="true">Previous</span>
            @else
                <button type="button" class="pd-btn pd-btn-sm" rel="prev"
                        wire:click="previousPage" wire:loading.attr="disabled"
                >Previous</button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pd-pagination-gap" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pd-btn pd-btn-sm is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <button type="button" class="pd-btn pd-btn-sm"
                                    wire:click="gotoPage({{ $page }})" wire:loading.attr="disabled"
                            >{{ $page }}</button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button" class="pd-btn pd-btn-sm" rel="next"
                        wire:click="nextPage" wire:loading.attr="disabled"
                >Next</button>
            @else
                <span class="pd-btn pd-btn-sm" aria-disabled="true">Next</span>
            @endif
        </div>
    </nav>
@endif
