{{--
    A customer's purchases — issued sales, newest first. Totals are the ones
    SalePricing wrote; nothing here adds anything up.
--}}
<div>
    <x-ui.card :title="__('manager_customers.purchases.title')" flush>
        <x-slot:actions>
            <a class="button button--ghost button--sm" href="{{ $salesUrl }}" wire:navigate><x-ui.icon name="sales" size="16" />{{ __('manager_customers.purchases.open_sales') }}</a>
        </x-slot:actions>
        @if($sales === [])
            <x-ui.empty-state compact icon="receipt" :title="__('manager_customers.purchases.none')" />
        @else
            <div class="table-shell table-shell--stack">
                <table>
                    <caption class="sr-only">{{ __('manager_customers.purchases.title') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('manager_customers.purchases.invoice') }}</th>
                            <th scope="col">{{ __('manager_customers.purchases.date') }}</th>
                            <th scope="col">{{ __('manager_customers.bookings.branch') }}</th>
                            <th scope="col" class="numeric">{{ __('manager_customers.bookings.total') }}</th>
                            <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sales as $sale)
                            <tr wire:key="sale-{{ $sale['uuid'] }}" @if($sale['voided']) data-muted="true" @endif>
                                <td data-label="{{ __('manager_customers.purchases.invoice') }}" data-primary>
                                    <span class="cell-title mono" dir="ltr">{{ $sale['number'] ?? '—' }}</span>
                                    @if($sale['voided'])
                                        <span class="cell-sub"><x-ui.status tone="danger" :label="__('manager_customers.purchases.voided')" :dot="false" />@if($sale['void_reason']) {{ $sale['void_reason'] }}@endif</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('manager_customers.purchases.date') }}">{{ $sale['date'] ?? '—' }}</td>
                                <td data-label="{{ __('manager_customers.bookings.branch') }}">{{ $sale['branch'] ?? '—' }}</td>
                                <td data-label="{{ __('manager_customers.bookings.total') }}" class="numeric">
                                    <span dir="ltr" class="tabular @if($sale['voided']) struck @endif">{{ $sale['total'] ?? '—' }}</span>
                                    @if($sale['discount'])<span class="cell-sub">{{ __('manager_customers.purchases.discount', ['amount' => $sale['discount']]) }}</span>@endif
                                </td>
                                <td class="actions">
                                    @if($sale['print_url'])
                                        <a class="button button--ghost button--sm" href="{{ $sale['print_url'] }}" target="_blank" rel="noopener"><x-ui.icon name="print" size="16" />{{ __('manager_customers.purchases.print') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
