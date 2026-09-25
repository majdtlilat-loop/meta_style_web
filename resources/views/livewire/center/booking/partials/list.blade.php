{{--
    List: the range as a table — or, with "Needs a new team member", every
    future booking whose person is no longer active (docs/15-BOOKING.md §17).
    Rows become stacked cards under 48rem. Contact details arrive already
    masked by the presenter.
--}}
<section class="bk-list stack--sm" aria-label="{{ $affectedList ? __('manager_booking.filters.affected') : $heading }}">
    @if($affectedList)
        <p class="muted bk-hint"><x-ui.icon name="info" size="14" />{{ __('manager_booking.calendar.affected_intro') }}</p>
    @endif

    <div class="table-shell table-shell--stack">
        @if($rows === [])
            <x-ui.empty-state :icon="$affectedList ? 'user-check' : ($hasFilters ? 'filter' : 'list')"
                :title="$affectedList ? __('manager_booking.empty.affected_title') : ($hasFilters ? __('manager_booking.empty.filtered_title') : __('manager_booking.empty.list_title'))">
                @if($hasFilters)
                    <button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('ui.actions.clear_filters') }}</button>
                @elseif($canCreate && ! $affectedList)
                    <button class="button button--sm" type="button" wire:click="startBooking"><x-ui.icon name="plus" size="16" />{{ __('manager_booking.actions.new') }}</button>
                @endif
            </x-ui.empty-state>
        @else
            <table>
                <caption class="sr-only">{{ __('manager_booking.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('manager_booking.table.when') }}</th>
                        <th scope="col">{{ __('manager_booking.table.customer') }}</th>
                        <th scope="col">{{ __('manager_booking.table.services') }}</th>
                        <th scope="col">{{ __('manager_booking.table.team') }}</th>
                        <th scope="col">{{ __('manager_booking.table.status') }}</th>
                        <th scope="col" class="numeric">{{ __('manager_booking.table.total') }}</th>
                        <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr wire:key="row-{{ $row['uuid'] }}" @if($row['muted']) data-muted="true" @endif @if($viewing === $row['uuid']) aria-selected="true" @endif>
                            <td data-label="{{ __('manager_booking.table.when') }}" data-primary>
                                <button class="cell-title" type="button" wire:click="open('{{ $row['uuid'] }}')">{{ $row['date_label'] }} · <span dir="ltr" class="tabular">{{ $row['time_label'] }}</span></button>
                                <span class="cell-sub"><span dir="ltr">{{ $row['reference'] ?? '—' }}</span>@if($row['branch']['name'] && count($branches) > 1) · {{ $row['branch']['name'] }}@endif</span>
                            </td>
                            <td data-label="{{ __('manager_booking.table.customer') }}">
                                <span class="cell-title">{{ $row['customer_name'] }}</span>
                                @if($row['contact_phone'])<span class="cell-sub" dir="ltr">{{ $row['contact_phone'] }}</span>@endif
                            </td>
                            <td data-label="{{ __('manager_booking.table.services') }}">
                                <span>{{ $row['services_label'] }}</span>
                                <span class="cell-sub">{{ $row['duration_label'] }}</span>
                            </td>
                            <td data-label="{{ __('manager_booking.table.team') }}">
                                <span>{{ $row['staff_label'] !== '' ? $row['staff_label'] : __('manager_booking.calendar.unassigned') }}</span>
                                @if($row['needs_staff'])<span class="cell-sub warning-text">{{ __('manager_booking.calendar.needs_staff') }}</span>@endif
                            </td>
                            <td data-label="{{ __('manager_booking.table.status') }}">
                                <x-ui.status :value="$row['status']" :label="$row['status_label']" />
                            </td>
                            <td data-label="{{ __('manager_booking.table.total') }}" class="numeric">
                                <x-ui.money :minor="$row['total']['amount']" :currency="$row['total']['currency']" />
                            </td>
                            <td class="actions">
                                <button class="button button--secondary button--sm" type="button" wire:click="open('{{ $row['uuid'] }}')">{{ __('manager_booking.actions.open') }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if($page !== null && $page->hasPages())
        {{ $page->links() }}
    @endif
</section>
