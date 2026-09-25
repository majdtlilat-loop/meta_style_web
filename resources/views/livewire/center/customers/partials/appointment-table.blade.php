{{-- Rows prepared by BookingsPanel::row(). --}}
<div class="table-shell table-shell--stack">
    <table>
        <caption class="sr-only">{{ $caption }}</caption>
        <thead>
            <tr>
                <th scope="col">{{ __('manager_customers.bookings.when') }}</th>
                <th scope="col">{{ __('manager_customers.bookings.services') }}</th>
                <th scope="col">{{ __('manager_customers.bookings.branch') }}</th>
                <th scope="col">{{ __('manager_customers.bookings.status') }}</th>
                <th scope="col" class="numeric">{{ __('manager_customers.bookings.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr wire:key="appointment-{{ $row['uuid'] }}">
                    <td data-label="{{ __('manager_customers.bookings.when') }}" data-primary>
                        <span class="cell-title">{{ $row['date'] }}</span>
                        <span class="cell-sub"><span dir="ltr" class="tabular">{{ $row['time'] }}</span>@if($row['reference']) · <span dir="ltr" class="mono">{{ $row['reference'] }}</span>@endif</span>
                    </td>
                    <td data-label="{{ __('manager_customers.bookings.services') }}">
                        @foreach($row['services'] as $service)
                            <span class="cell-title">{{ $service['name'] }}</span>
                            @if($service['employee'])<span class="cell-sub">{{ __('manager_customers.bookings.with', ['name' => $service['employee']]) }}</span>@endif
                        @endforeach
                    </td>
                    <td data-label="{{ __('manager_customers.bookings.branch') }}">{{ $row['branch'] ?? '—' }}</td>
                    <td data-label="{{ __('manager_customers.bookings.status') }}"><x-ui.status :value="$row['status']" :label="$row['status_label']" /></td>
                    <td data-label="{{ __('manager_customers.bookings.total') }}" class="numeric"><span dir="ltr" class="tabular">{{ $row['total'] ?? '—' }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
