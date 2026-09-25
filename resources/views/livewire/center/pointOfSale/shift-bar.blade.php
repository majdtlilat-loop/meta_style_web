{{-- The till bar: which branch, and this cashier's shift there. --}}
<section class="till-bar pos-shift-bar" aria-label="{{ __('manager_pos.shift.label') }}">
    <div class="field field--inline">
        <label for="till-branch">{{ __('Branch') }}</label>
        <select id="till-branch" wire:model.live="branch">
            @foreach($branches as $option)
                <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
            @endforeach
        </select>
    </div>

    @if($canShift)
        @if($shift === null)
            <x-ui.status tone="warning" :label="__('No open shift.')" />
            <form class="till-bar__form pos-shift-bar__form" wire:submit="openShift">
                <input type="text" dir="ltr" inputmode="decimal" wire:model="openingCash" placeholder="{{ __('Opening cash (optional)') }}" aria-label="{{ __('Opening cash (optional)') }}" autocomplete="off">
                <input type="text" wire:model="shiftNote" placeholder="{{ __('Opening note (optional)') }}" aria-label="{{ __('Opening note (optional)') }}" maxlength="190">
                <button class="button button--secondary" type="submit" wire:loading.attr="data-loading" wire:target="openShift"><x-ui.icon name="play" size="16" />{{ __('Open shift') }}</button>
            </form>
        @else
            <div class="pos-shift-bar__status">
                <x-ui.status tone="success" :label="__('Shift open since :time', ['time' => $shift['opened_at']])" />
                <span class="cell-sub">
                    {{ trans_choice('manager_pos.shift.sales', $shift['sales'], ['count' => $shift['sales']]) }}@foreach($shift['totals'] as $total) · <span dir="ltr" class="tabular">{{ $total }}</span>@endforeach
                </span>
            </div>
            <details class="dropdown till-bar__close" data-popover>
                <summary class="button button--ghost button--sm">{{ __('Close shift') }}<x-ui.icon name="chevron-down" size="14" /></summary>
                <form class="dropdown__panel stack stack--sm" wire:submit="closeShift('{{ $shift['uuid'] }}')" wire:confirm="{{ __('Close your shift?') }}" data-confirm-title="{{ __('Close shift') }}">
                    @if($countsDrawer)
                        {{-- A blind count: the expected amount is shown only after it is entered. --}}
                        <x-ui.field :label="__('Cash counted in the drawer')" for="counted-cash" name="countedCash" required>
                            <input id="counted-cash" type="text" dir="ltr" inputmode="decimal" wire:model="countedCash" required autocomplete="off">
                        </x-ui.field>
                    @endif
                    <x-ui.field :label="__('Closing note (optional)')" for="closing-note" name="shiftNote">
                        <input id="closing-note" type="text" wire:model="shiftNote" maxlength="190">
                    </x-ui.field>
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="closeShift">{{ __('Close shift') }}</button>
                </form>
            </details>
        @endif
    @endif
</section>
