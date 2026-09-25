@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp
{{-- Meta Style pagination for Livewire lists: counts, then compact page links. --}}
@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="{{ __('ui.pagination.label') }}">
        <p>{{ __('ui.pagination.showing', ['first' => $paginator->firstItem() ?? 0, 'last' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()]) }}</p>
        <div class="pagination__pages">
            @if ($paginator->onFirstPage())
                <span class="pagination__link" aria-disabled="true" aria-label="{{ __('ui.pagination.previous') }}"><x-ui.icon name="chevron-left" /></span>
            @else
                <button type="button" class="pagination__link" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" aria-label="{{ __('ui.pagination.previous') }}"><x-ui.icon name="chevron-left" /></button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pagination__link" aria-disabled="true">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                            @if ($page == $paginator->currentPage())
                                <span class="pagination__link" aria-current="page">{{ $page }}</span>
                            @else
                                <button type="button" class="pagination__link" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" aria-label="{{ __('ui.pagination.page', ['page' => $page]) }}">{{ $page }}</button>
                            @endif
                        </span>
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button" class="pagination__link" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" aria-label="{{ __('ui.pagination.next') }}"><x-ui.icon name="chevron-right" /></button>
            @else
                <span class="pagination__link" aria-disabled="true" aria-label="{{ __('ui.pagination.next') }}"><x-ui.icon name="chevron-right" /></span>
            @endif
        </div>
    </nav>
@endif
