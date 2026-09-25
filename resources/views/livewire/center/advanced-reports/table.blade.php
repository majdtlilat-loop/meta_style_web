{{--
    A detail table: every header sorts in the browser (numbers as numbers,
    from each cell's raw `sort` value), a page of rows at a time, stacked into
    labelled cards on a phone. Cells arrive formatted and authorized; a new
    data set arrives under a new key, so a sort never outlives its numbers.
    `$table`: title, columns [label, numeric], rows (text), sort (raw), page_size.
--}}
<div class="adv-sortable" x-data="advancedTable({{ (int) $table['page_size'] }})" wire:key="adv-table-{{ md5($table['title'].json_encode($table['sort'])) }}"
     data-range="{{ __('manager_advanced.table.range', ['from' => ':from', 'to' => ':to', 'total' => ':total']) }}">
    <div class="table-shell table-shell--stack adv-table" tabindex="0" role="region" aria-label="{{ $table['title'] }}">
        <table>
            <thead>
                <tr>
                    @foreach($table['columns'] as $index => $column)
                        <th scope="col" @class(['numeric' => $column['numeric']]) aria-sort="none">
                            <button type="button" class="table-sort" x-on:click="sort({{ $index }})">{{ $column['label'] }}<x-ui.icon name="chevrons-up-down" size="12" /></button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody wire:ignore>
                @foreach($table['rows'] as $position => $row)
                    <tr @if($position >= $table['page_size']) hidden @endif>
                        @foreach($row as $index => $cell)
                            <td @class(['numeric' => $table['columns'][$index]['numeric']]) data-label="{{ $table['columns'][$index]['label'] }}" data-sort="{{ $table['sort'][$position][$index] ?? '' }}" @if($index === 0) data-primary @endif>
                                @if($index === 0)<span class="cell-title">{{ $cell }}</span>@else<span dir="auto">{{ $cell }}</span>@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if(count($table['rows']) > $table['page_size'])
        <nav class="table-footer adv-pager" aria-label="{{ __('manager_advanced.table.pages', ['table' => $table['title']]) }}">
            <span aria-live="polite" x-text="summary">{{ __('manager_advanced.table.range', ['from' => 1, 'to' => $table['page_size'], 'total' => count($table['rows'])]) }}</span>
            <span class="adv-pager__buttons">
                <button type="button" class="icon-button icon-button--sm" x-on:click="previous()" x-bind:disabled="page === 0" disabled aria-label="{{ __('manager_advanced.table.previous') }}"><x-ui.icon name="chevron-left" size="16" /></button>
                <button type="button" class="icon-button icon-button--sm" x-on:click="next()" x-bind:disabled="last" aria-label="{{ __('manager_advanced.table.next') }}"><x-ui.icon name="chevron-right" size="16" /></button>
            </span>
        </nav>
    @endif
</div>
