{{--
    A branch's sales history, and the invoice behind each.

    docs/18-SALES.md §45. Not a finance report: no revenue, no margin. The list a
    manager opens to find a sale, share its invoice or void it. Every label,
    time and figure arrives ready from the component and the presenters.
--}}
<div class="stack pos-page">
    <x-ui.page-header :title="__('Sales')">
        @if($canSell)
            <x-slot:actions>
                <a class="button" href="{{ route('center.pos') }}" wire:navigate><x-ui.icon name="pos" size="16" />{{ __('New sale') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.center.finance.tabs')

    {{-- Issued sales stay readable after a downgrade; new ones need the POS. --}}
    @if(! $hasPos && $offer !== null)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    @if($error !== '' && $open === '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
    @if($saved !== '' && $open === '')<div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>@endif

    <form class="filter-bar pos-filters" role="search" x-on:submit.prevent aria-label="{{ __('ui.actions.filters') }}">
        @if(count($branches) > 1)
            <div class="field">
                <label for="sales-branch">{{ __('Branch') }}</label>
                <select id="sales-branch" wire:model.live="branch">
                    @foreach($branches as $option)
                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="field filter-bar__search">
            <label for="sales-search">{{ __('ui.actions.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="sales-search" type="search" wire:model.live.debounce.400ms="term" placeholder="{{ __('manager_pos.history.search') }}" autocomplete="off">
            </div>
        </div>
        <div class="field">
            <label for="sales-status">{{ __('Status') }}</label>
            <select id="sales-status" wire:model.live="status">
                <option value="">{{ __('Issued and voided') }}</option>
                <option value="finalized">{{ __('Issued') }}</option>
                <option value="voided">{{ __('Voided') }}</option>
                <option value="draft">{{ __('Open drafts') }}</option>
            </select>
        </div>
        @if($cashiers !== [])
            <div class="field">
                <label for="sales-cashier">{{ __('manager_pos.history.cashier') }}</label>
                <select id="sales-cashier" wire:model.live="cashier">
                    <option value="">{{ __('manager_pos.history.all_cashiers') }}</option>
                    @foreach($cashiers as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if($hasFilters)
            <div class="filter-bar__actions">
                <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('ui.actions.clear_filters') }}</button>
            </div>
        @endif
    </form>

    @include('livewire.center.finance.window')

    @if($listError !== '')
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $listError }}</p></div>
    @endif

    @if($totals !== [] && $status !== 'draft')
        <div class="pos-strip" wire:loading.class="is-refreshing">
            @foreach($totals as $total)
                <div class="pos-strip__item" @if($total['tone']) data-tone="{{ $total['tone'] }}" @endif>
                    <span class="pos-strip__label">{{ $total['label'] }}</span>
                    <strong class="tabular" dir="ltr">{{ $total['amount'] }}</strong>
                    <span class="cell-sub">{{ trans_choice('manager_pos.history.sales_count', $total['count'], ['count' => $total['count']]) }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="branch,status,term,cashier,setRange,from,until,gotoPage,nextPage,previousPage,clearFilters">
        @if($sales === [])
            <x-ui.empty-state :icon="$hasFilters ? 'filter' : 'sales'" :title="$hasFilters ? __('manager_pos.history.no_match') : __('No sales.')">
                @if($hasFilters)
                    <button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('ui.actions.clear_filters') }}</button>
                @elseif($canSell)
                    <a class="button button--sm" href="{{ route('center.pos') }}" wire:navigate>{{ __('New sale') }}</a>
                @endif
            </x-ui.empty-state>
        @else
            <table>
                <caption class="sr-only">{{ __('Sales') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('Invoice') }}</th>
                        <th scope="col">{{ __('Customer') }}</th>
                        <th scope="col">{{ __('manager_pos.history.cashier') }}</th>
                        <th scope="col">{{ __('Status') }}</th>
                        <th scope="col" class="numeric">{{ __('Total') }}</th>
                        <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sales as $row)
                        <tr wire:key="sale-{{ $row['uuid'] }}" @if($open === $row['uuid']) aria-selected="true" @endif>
                            <td data-label="{{ __('Invoice') }}" data-primary>
                                <button class="cell-title text-button" type="button" wire:click="show('{{ $row['uuid'] }}')" dir="ltr">{{ $row['invoice']['number'] ?? __('Draft sale') }}</button>
                                <span class="cell-sub">{{ $row['when'] }}</span>
                            </td>
                            <td data-label="{{ __('Customer') }}">{{ $row['customer']['name'] ?? __('Walk-up customer') }}</td>
                            <td data-label="{{ __('manager_pos.history.cashier') }}">{{ $row['cashier'] ?? '—' }}</td>
                            <td data-label="{{ __('Status') }}"><x-ui.status :value="$row['status']" :label="$row['status_label']" /></td>
                            <td data-label="{{ __('Total') }}" class="numeric"><span dir="ltr" class="tabular">{{ $row['grand_total']['formatted'] }}</span></td>
                            <td class="actions">
                                @if($row['status'] === 'draft' && $canSell)
                                    <a class="button button--secondary button--sm" href="{{ route('center.pos', ['sale' => $row['uuid']]) }}" wire:navigate>{{ __('Open at till') }}</a>
                                @endif
                                <button class="button button--ghost button--sm" type="button" wire:click="show('{{ $row['uuid'] }}')">{{ __('Details') }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if($paginator !== null)
        {{ $paginator->links() }}
    @endif

    @if($detail !== null)
        @include('livewire.center.sales.detail')
    @endif
</div>
