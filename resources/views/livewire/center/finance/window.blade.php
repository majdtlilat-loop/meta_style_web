{{--
    The money screens' date window: presets, and From / Until when custom.
    $window comes from PosFinance\HasMoneyWindow::windowControl() — branch-local
    days, the branch's own "today" as the latest pickable date.
--}}
<div class="pos-window">
    <div class="segmented segmented--scroll" role="group" aria-label="{{ __('manager_finance.range.label') }}">
        @foreach($window['presets'] as $preset)
            <button type="button" wire:click="setRange('{{ $preset['key'] }}')" aria-pressed="{{ $preset['active'] ? 'true' : 'false' }}">@if($preset['key'] === 'custom')<x-ui.icon name="calendar" size="14" />@endif{{ $preset['label'] }}</button>
        @endforeach
    </div>
    @if($window['custom'])
        <div class="pos-window__custom">
            <div class="field">
                <label for="window-from">{{ __('ui.fields.from') }}</label>
                <input id="window-from" type="date" wire:model.live="from" max="{{ $window['max'] }}">
            </div>
            <div class="field">
                <label for="window-until">{{ __('manager_finance.range.until') }}</label>
                <input id="window-until" type="date" wire:model.live="until" min="{{ $window['from'] }}" max="{{ $window['max'] }}">
            </div>
        </div>
    @endif
    <p class="pos-window__summary"><x-ui.icon name="calendar" size="14" /><span>{{ $window['summary'] }}</span><span class="spinner" wire:loading aria-hidden="true"></span></p>
</div>
