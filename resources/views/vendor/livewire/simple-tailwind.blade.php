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
@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="{{ __('ui.pagination.label') }}">
        <span></span>
        <div class="pagination__pages">
            @if ($paginator->onFirstPage())
                <span class="pagination__link" aria-disabled="true"><x-ui.icon name="chevron-left" />{{ __('ui.pagination.previous') }}</span>
            @else
                <button type="button" class="pagination__link" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"><x-ui.icon name="chevron-left" />{{ __('ui.pagination.previous') }}</button>
            @endif
            @if ($paginator->hasMorePages())
                <button type="button" class="pagination__link" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">{{ __('ui.pagination.next') }}<x-ui.icon name="chevron-right" /></button>
            @else
                <span class="pagination__link" aria-disabled="true">{{ __('ui.pagination.next') }}<x-ui.icon name="chevron-right" /></span>
            @endif
        </div>
    </nav>
@endif
