@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="{{ __('ui.pagination.label') }}">
        <span></span>
        <div class="pagination__pages">
            @if ($paginator->onFirstPage())
                <span class="pagination__link" aria-disabled="true"><x-ui.icon name="chevron-left" />{{ __('ui.pagination.previous') }}</span>
            @else
                <a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-ui.icon name="chevron-left" />{{ __('ui.pagination.previous') }}</a>
            @endif
            @if ($paginator->hasMorePages())
                <a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('ui.pagination.next') }}<x-ui.icon name="chevron-right" /></a>
            @else
                <span class="pagination__link" aria-disabled="true">{{ __('ui.pagination.next') }}<x-ui.icon name="chevron-right" /></span>
            @endif
        </div>
    </nav>
@endif
