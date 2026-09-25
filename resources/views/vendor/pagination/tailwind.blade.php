{{-- Meta Style pagination for classic (non-Livewire) paginators. --}}
@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="{{ __('ui.pagination.label') }}">
        <p>{{ __('ui.pagination.showing', ['first' => $paginator->firstItem() ?? 0, 'last' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()]) }}</p>
        <div class="pagination__pages">
            @if ($paginator->onFirstPage())
                <span class="pagination__link" aria-disabled="true" aria-label="{{ __('ui.pagination.previous') }}"><x-ui.icon name="chevron-left" /></span>
            @else
                <a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('ui.pagination.previous') }}"><x-ui.icon name="chevron-left" /></a>
            @endif
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pagination__link" aria-disabled="true">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pagination__link" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="pagination__link" href="{{ $url }}" aria-label="{{ __('ui.pagination.page', ['page' => $page]) }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('ui.pagination.next') }}"><x-ui.icon name="chevron-right" /></a>
            @else
                <span class="pagination__link" aria-disabled="true" aria-label="{{ __('ui.pagination.next') }}"><x-ui.icon name="chevron-right" /></span>
            @endif
        </div>
    </nav>
@endif
