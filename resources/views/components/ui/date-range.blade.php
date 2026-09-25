@props([
    'range',
    'current',
    'open' => false,
])
{{--
    The one date-range control. Presets first; "Custom" reveals From / To and
    only applies on confirm. Every chart and card below it follows it.
    `current` is the resolved App\Kernel\Time\DateRange.
--}}
@php
    $presets = ['today', 'last_7_days', 'this_month', 'last_30_days'];
    $locale = app()->getLocale();
    $describe = static fn (\App\Kernel\Time\DateRange $r): string => $r->days() === 1
        ? $r->from->locale($locale)->isoFormat('D MMM YYYY')
        : $r->from->locale($locale)->isoFormat('D MMM').' – '.$r->to->locale($locale)->isoFormat('D MMM YYYY');
@endphp
<div {{ $attributes->class(['date-range']) }}>
    <div class="date-range__bar">
        <div class="segmented" role="group" aria-label="{{ __('ui.range.label') }}">
            @foreach($presets as $preset)
                <button type="button" wire:click="setRange('{{ $preset }}')" aria-pressed="{{ $range === $preset ? 'true' : 'false' }}">{{ __('ui.range.'.$preset) }}</button>
            @endforeach
            <button type="button" wire:click="setRange('custom')" aria-pressed="{{ $range === 'custom' ? 'true' : 'false' }}" aria-expanded="{{ $open ? 'true' : 'false' }}"><x-ui.icon name="calendar" size="14" />{{ __('ui.range.custom') }}</button>
        </div>
        <p class="date-range__summary">
            <span>{{ $describe($current) }}</span>
            <span class="subtle">{{ __('ui.range.compared_with', ['period' => $describe($current->previous())]) }}</span>
            <span class="spinner" wire:loading aria-hidden="true"></span>
        </p>
    </div>
    @if($open)
        <form class="date-range__custom" wire:submit="applyCustomRange" novalidate>
            <x-ui.field :label="__('ui.fields.from')" for="range-from" name="customFrom">
                <input id="range-from" type="date" wire:model="customFrom" max="{{ now()->toDateString() }}" @error('customFrom') aria-invalid="true" @enderror>
            </x-ui.field>
            <x-ui.field :label="__('ui.fields.to')" for="range-to" name="customTo">
                <input id="range-to" type="date" wire:model="customTo" max="{{ now()->toDateString() }}" @error('customTo') aria-invalid="true" @enderror>
            </x-ui.field>
            <div class="date-range__actions">
                <x-ui.button variant="ghost" wire:click="cancelCustomRange">{{ __('ui.actions.cancel') }}</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="applyCustomRange">{{ __('ui.actions.apply') }}</x-ui.button>
            </div>
        </form>
    @endif
</div>
