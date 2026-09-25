{{--
    A detail table: every header sorts (in the browser, instantly), ten rows a
    page, stacked into labelled cards on a phone. The rows are server-rendered
    and pre-formatted; a new data set arrives under a new key (and replaces the
    table), while a refetch that leaves these rows unchanged leaves the
    viewer's sort order, page and header state alone (wire:ignore).
--}}
<div class="std-table" x-data="reportTable({{ (int) $table['page_size'] }})" wire:key="std-table-{{ $id }}-{{ $table['hash'] }}" wire:ignore
     data-range="{{ __('manager_reports.std.table.range', ['from' => ':from', 'to' => ':to', 'total' => ':total']) }}">
    <div class="table-shell table-shell--stack" tabindex="0" role="region" aria-label="{{ $title }}">
        <table>
            <thead>
                <tr>
                    @foreach($table['columns'] as $index => $column)
                        <th scope="col" @class(['numeric' => $column['numeric']]) aria-sort="none" data-column="{{ $index }}">
                            <button type="button" class="table-sort" x-on:click="sort({{ $index }})">{{ $column['label'] }}<x-ui.icon name="chevrons-up-down" size="12" /></button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($table['rows'] as $position => $row)
                    <tr @if($position >= $table['page_size']) hidden @endif>
                        @foreach($row as $index => $cell)
                            <td data-label="{{ $table['columns'][$index]['label'] }}" data-sort="{{ $cell['sort'] }}" @class(['numeric' => $table['columns'][$index]['numeric']]) @if($index === 0) data-primary @endif>
                                @if(! empty($cell['href']))
                                    <a class="cell-link" href="{{ $cell['href'] }}" wire:navigate>{{ $cell['text'] }}</a>
                                @elseif(isset($cell['tone']))
                                    <span class="std-change" data-tone="{{ $cell['tone'] }}" dir="ltr">{{ $cell['text'] }}</span>
                                @else
                                    <span @if($table['columns'][$index]['numeric']) dir="ltr" @endif>{{ $cell['text'] }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if(count($table['rows']) > $table['page_size'])
        <nav class="table-footer std-pager" aria-label="{{ __('manager_reports.std.table.pages', ['table' => $title]) }}">
            <span class="std-pager__range" aria-live="polite" x-text="summary">{{ __('manager_reports.std.table.range', ['from' => 1, 'to' => $table['page_size'], 'total' => count($table['rows'])]) }}</span>
            <span class="std-pager__buttons">
                <button type="button" class="icon-button icon-button--sm" x-on:click="previous()" x-bind:disabled="page === 0" disabled aria-label="{{ __('manager_reports.std.table.previous') }}"><x-ui.icon name="chevron-left" size="16" /></button>
                <button type="button" class="icon-button icon-button--sm" x-on:click="next()" x-bind:disabled="last" aria-label="{{ __('manager_reports.std.table.next') }}"><x-ui.icon name="chevron-right" size="16" /></button>
            </span>
        </nav>
    @endif
</div>
