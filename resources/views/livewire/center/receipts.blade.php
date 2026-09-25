{{--
    Receipts: payments taken and refunds made at a branch, by branch-local day.

    docs/19-PAYMENTS.md §3. History — readable after a downgrade. The
    totals are the Payments module's own sums per currency; nothing is added
    up here. A pending online payment is not money received and sits apart.
--}}
<div class="stack pos-page">
    <x-ui.page-header :title="__('manager_finance.receipts.title')" />

    @include('livewire.center.finance.tabs')

    @if($denied)
        <x-ui.empty-state icon="lock" :title="__('manager_finance.denied.title')" :description="__('manager_finance.receipts.denied')" />
    @else
        @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif

        <div class="segmented" role="tablist" aria-label="{{ __('manager_finance.receipts.title') }}">
            <button type="button" role="tab" wire:click="showList('payments')" aria-selected="{{ $list === 'payments' ? 'true' : 'false' }}" aria-pressed="{{ $list === 'payments' ? 'true' : 'false' }}"><x-ui.icon name="wallet" size="14" />{{ __('manager_finance.receipts.payments') }}</button>
            <button type="button" role="tab" wire:click="showList('refunds')" aria-selected="{{ $list === 'refunds' ? 'true' : 'false' }}" aria-pressed="{{ $list === 'refunds' ? 'true' : 'false' }}"><x-ui.icon name="undo" size="14" />{{ __('manager_finance.receipts.refunds') }}</button>
        </div>

        <form class="filter-bar pos-filters" role="search" x-on:submit.prevent aria-label="{{ __('ui.actions.filters') }}">
            @if(count($branches) > 1)
                <div class="field">
                    <label for="receipts-branch">{{ __('Branch') }}</label>
                    <select id="receipts-branch" wire:model.live="branch">
                        @foreach($branches as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="field filter-bar__search">
                <label for="receipts-search">{{ __('ui.actions.search') }}</label>
                <div class="search-input">
                    <x-ui.icon name="search" />
                    <input id="receipts-search" type="search" wire:model.live.debounce.400ms="term" placeholder="{{ __('manager_finance.receipts.search') }}" autocomplete="off" dir="auto">
                </div>
            </div>
            <div class="field">
                <label for="receipts-method">{{ __('manager_finance.receipts.method') }}</label>
                <select id="receipts-method" wire:model.live="method">
                    <option value="">{{ __('manager_finance.receipts.all_methods') }}</option>
                    @foreach($methods as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="receipts-status">{{ __('Status') }}</label>
                <select id="receipts-status" wire:model.live="status">
                    <option value="">{{ __('manager_finance.receipts.all_statuses') }}</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            @if($hasFilters)
                <div class="filter-bar__actions">
                    <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('ui.actions.clear_filters') }}</button>
                </div>
            @endif
        </form>

        @include('livewire.center.finance.window')

        @if($totals !== null)
            <div class="stat-grid" wire:loading.class="is-refreshing">
                <x-ui.stat icon="wallet" :label="__('manager_finance.receipts.received')" :value="$totals['received']" :hint="trans_choice('manager_finance.receipts.payments_count', $totals['received_count'], ['count' => $totals['received_count']])">
                    @if($totals['by_method'] !== [])
                        <dl class="stat__breakdown">
                            @foreach($totals['by_method'] as $row)
                                <div><dt>{{ $row['label'] }}</dt><dd dir="ltr">{{ $row['amount'] }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </x-ui.stat>
                <x-ui.stat icon="undo" :label="__('manager_finance.receipts.refunded')" :value="$totals['refunded']" :hint="trans_choice('manager_finance.receipts.refunds_count', $totals['refunded_count'], ['count' => $totals['refunded_count']])" />
                @if($totals['pending'] !== null)
                    <x-ui.stat icon="clock" tone="warning" :label="__('manager_finance.receipts.pending')" :value="$totals['pending']" :hint="__('manager_finance.receipts.pending_hint')" />
                @endif
            </div>
            @if($totals['mixed'])
                <p class="field-help">{{ __('manager_finance.currency_mixed') }}</p>
            @endif
        @endif

        <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="branch,method,status,term,setRange,from,until,showList,gotoPage,nextPage,previousPage,clearFilters">
            @if($rows === [])
                <x-ui.empty-state :icon="$hasFilters ? 'filter' : 'receipt'" :title="$hasFilters ? __('manager_finance.receipts.no_match') : ($list === 'refunds' ? __('manager_finance.receipts.no_refunds') : __('manager_finance.receipts.no_payments'))">
                    @if($hasFilters)<button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('ui.actions.clear_filters') }}</button>@endif
                </x-ui.empty-state>
            @else
                <table>
                    <caption class="sr-only">{{ $list === 'refunds' ? __('manager_finance.receipts.refunds') : __('manager_finance.receipts.payments') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('manager_finance.receipts.when') }}</th>
                            <th scope="col">{{ __('Invoice') }}</th>
                            <th scope="col">{{ __('manager_finance.receipts.method') }}</th>
                            <th scope="col">{{ $list === 'refunds' ? __('manager_finance.receipts.requested_by') : __('manager_finance.receipts.taken_by') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col" class="numeric">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr wire:key="{{ $list }}-{{ $row['uuid'] }}">
                                <td data-label="{{ __('manager_finance.receipts.when') }}" data-primary class="nowrap"><span class="cell-title">{{ $row['when'] }}</span></td>
                                <td data-label="{{ __('Invoice') }}">
                                    @if($row['sale'] !== null && $canOpenSales)
                                        <a class="cell-link" href="{{ route('center.sales', ['sale' => $row['sale']]) }}" wire:navigate dir="ltr">{{ $row['invoice_number'] ?? '—' }}</a>
                                    @else
                                        <span dir="ltr">{{ $row['invoice_number'] ?? '—' }}</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('manager_finance.receipts.method') }}">
                                    {{ $row['method_label'] }}
                                    @if(($row['manual_method_label'] ?? null) !== null)<span class="cell-sub">{{ $row['manual_method_label'] }}@if(($row['manual_reference'] ?? null) !== null) · <span dir="ltr">{{ $row['manual_reference'] }}</span>@endif</span>@endif
                                    @if(($row['reason'] ?? null) !== null)<span class="cell-sub">{{ $row['reason'] }}</span>@endif
                                </td>
                                <td data-label="{{ $list === 'refunds' ? __('manager_finance.receipts.requested_by') : __('manager_finance.receipts.taken_by') }}">{{ $row['collected_by'] ?? $row['requested_by'] ?? '—' }}</td>
                                <td data-label="{{ __('Status') }}">
                                    <x-ui.status :tone="$row['tone']" :label="$row['status_label']" />
                                    @if($row['refunded'] ?? false)<span class="cell-sub">{{ __('manager_finance.receipts.partly_refunded') }}</span>@endif
                                </td>
                                <td data-label="{{ __('Amount') }}" class="numeric"><span dir="ltr" class="tabular @if($list === 'refunds') text-danger @endif">@if($list === 'refunds')−@endif{{ $row['amount']['formatted'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if($paginator !== null)
            {{ $paginator->links() }}
        @endif
    @endif
</div>
