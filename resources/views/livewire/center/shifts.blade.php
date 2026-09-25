{{--
    Cashier shifts and drawer counts. docs/20-FINANCE.md §§31–33.

    The count is blind: the expected cash of an OPEN shift is never shown here.
    Figures, labels and local times arrive ready from the component.
--}}
<div class="stack pos-page">
    <x-ui.page-header :title="__('manager_finance.shifts.title')">
        @if($hasPos)
            <x-slot:actions>
                <a class="button button--secondary" href="{{ route('center.pos') }}" wire:navigate><x-ui.icon name="pos" size="16" />{{ __('ui.manager_nav.items.pos') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.center.finance.tabs')

    @if(! $hasPos && $offer !== null)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    @if($denied)
        <x-ui.empty-state icon="lock" :title="__('manager_finance.denied.title')" :description="__('manager_finance.shifts.denied')" />
    @else
        @if($error !== '' && $closing === '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
        @if($saved !== '')<div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>@endif
        @if($listError !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $listError }}</p></div>@endif

        @if(count($branches) > 1)
            <div class="filter-bar">
                <div class="field">
                    <label for="shifts-branch">{{ __('Branch') }}</label>
                    <select id="shifts-branch" wire:model.live="branch">
                        @foreach($branches as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif

        <x-ui.card :title="__('manager_finance.shifts.open_now')" flush>
            @if($open === [])
                <x-ui.empty-state compact icon="clock" :title="__('manager_finance.shifts.none_open')" />
            @else
                <ul class="row-list pos-shifts">
                    @foreach($open as $shift)
                        <li class="row-list__item" wire:key="open-{{ $shift['uuid'] }}">
                            <span class="row-list__icon" data-tone="success" aria-hidden="true"><x-ui.icon name="user" /></span>
                            <div class="row-list__body">
                                <span class="cell-title">{{ $shift['cashier'] }}@if($shift['mine']) <span class="badge">{{ __('manager_finance.shifts.you') }}</span>@endif</span>
                                <span class="cell-sub">{{ __('manager_finance.shifts.since', ['time' => $shift['opened_at']]) }} · {{ trans_choice('manager_pos.shift.sales', $shift['sales'], ['count' => $shift['sales']]) }}@foreach($shift['totals'] as $total) · <span dir="ltr">{{ $total }}</span>@endforeach</span>
                                @if($shift['opening_cash'] !== null)<span class="cell-sub">{{ __('manager_finance.shifts.opening_cash', ['amount' => $shift['opening_cash']]) }}</span>@endif
                            </div>
                            @if($shift['can_close'])
                                <button class="button button--secondary button--sm" type="button" wire:click="startClose('{{ $shift['uuid'] }}')">{{ __('Close shift') }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        @include('livewire.center.finance.window')

        <x-ui.card :title="__('manager_finance.shifts.closed')" flush>
            @if($closed === [])
                <x-ui.empty-state compact icon="history" :title="__('manager_finance.shifts.none_closed')" />
            @else
                <div class="table-shell table-shell--stack">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Cashier') }}</th>
                                <th scope="col">{{ __('manager_finance.shifts.period') }}</th>
                                <th scope="col" class="numeric">{{ __('manager_finance.shifts.sales') }}</th>
                                <th scope="col" class="numeric">{{ __('Expected') }}</th>
                                <th scope="col" class="numeric">{{ __('Counted') }}</th>
                                <th scope="col" class="numeric">{{ __('Difference') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($closed as $shift)
                                <tr wire:key="closed-{{ $shift['uuid'] }}">
                                    <td data-label="{{ __('Cashier') }}" data-primary>
                                        <span class="cell-title">{{ $shift['cashier'] }}</span>
                                        @if($shift['closed_by'] !== null)<span class="cell-sub">{{ __('manager_finance.shifts.closed_by', ['name' => $shift['closed_by']]) }}</span>@endif
                                    </td>
                                    <td data-label="{{ __('manager_finance.shifts.period') }}"><span class="nowrap">{{ $shift['opened_at'] }}</span> – <span class="nowrap">{{ $shift['closed_at'] }}</span></td>
                                    <td data-label="{{ __('manager_finance.shifts.sales') }}" class="numeric">
                                        <span class="tabular">{{ $shift['sales'] }}</span>
                                        @foreach($shift['totals'] as $total)<span class="cell-sub" dir="ltr">{{ $total }}</span>@endforeach
                                    </td>
                                    @if($shift['counted'] !== null)
                                        <td data-label="{{ __('Expected') }}" class="numeric"><span dir="ltr" class="tabular">{{ $shift['counted']['expected'] }}</span></td>
                                        <td data-label="{{ __('Counted') }}" class="numeric"><span dir="ltr" class="tabular">{{ $shift['counted']['counted'] }}</span></td>
                                        <td data-label="{{ __('Difference') }}" class="numeric">
                                            <x-ui.status :tone="$shift['counted']['tone']" :label="$shift['counted']['label']" :dot="false" />
                                            <span dir="ltr" class="tabular cell-sub">{{ $shift['counted']['variance'] }}</span>
                                        </td>
                                    @else
                                        <td data-label="{{ __('Expected') }}" class="numeric muted">—</td>
                                        <td data-label="{{ __('Counted') }}" class="numeric muted">—</td>
                                        <td data-label="{{ __('Difference') }}" class="numeric"><span class="cell-sub">{{ __('manager_finance.shifts.not_counted') }}</span></td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    @if($closingRow !== null)
        <x-ui.modal :title="__('Close shift')" :description="__('manager_finance.shifts.close_for', ['name' => $closingRow['cashier']])" icon="clock" submit="close" close="cancelClose">
            <div class="stack stack--sm">
                @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
                @if($countsDrawer)
                    {{-- A blind count: the expected amount is shown only after it is entered. --}}
                    <x-ui.field :label="__('Cash counted in the drawer')" for="shift-counted" name="countedCash" required>
                        <input id="shift-counted" type="text" dir="ltr" inputmode="decimal" wire:model="countedCash" required autocomplete="off">
                    </x-ui.field>
                @endif
                <x-ui.field :label="__('Closing note (optional)')" for="shift-note" name="closeNote">
                    <input id="shift-note" type="text" wire:model="closeNote" maxlength="190">
                </x-ui.field>
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="cancelClose">{{ __('Cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="close">{{ __('Close shift') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
