{{--
    Move a booking. The times are the engine's (AvailabilityQuery::forMove —
    the visit's stored shape, ignoring its own current place); the move itself
    re-checks everything under the branch lock (docs/15 §7).
--}}
<section class="drawer-section bk-confirm" aria-labelledby="bk-m-title">
    <h3 id="bk-m-title">{{ __('manager_booking.move.title') }}</h3>

    <x-ui.notice tone="danger" :message="$error" dismiss="dismissNotice" />

    <div class="bk-daypicker">
        <button class="icon-button icon-button--bordered" type="button" wire:click="shift(-1)" aria-label="{{ __('manager_booking.calendar.previous.day') }}"><x-ui.icon name="chevron-left" /></button>
        <label class="sr-only" for="bk-m-date">{{ __('manager_booking.composer.date') }}</label>
        <input id="bk-m-date" type="date" wire:model.live="date" required>
        <button class="icon-button icon-button--bordered" type="button" wire:click="shift(1)" aria-label="{{ __('manager_booking.calendar.next.day') }}"><x-ui.icon name="chevron-right" /></button>
        <span class="muted bk-daypicker__label">{{ $dateLabel }}</span>
    </div>

    <div wire:loading.class="is-refreshing" wire:target="shift,date,find">
        @if($searched && $periods === [] && $error === '')
            <x-ui.empty-state compact icon="clock" :title="__('manager_booking.composer.no_times', ['date' => $dateLabel])" />
        @elseif($periods !== [])
            <div class="bk-slots">
                @foreach($periods as $period)
                    <div class="bk-slots__group" wire:key="mperiod-{{ $period['key'] }}">
                        <p class="bk-slots__label">{{ $period['label'] }}</p>
                        <div class="bk-slots__grid" role="group" aria-label="{{ $period['label'] }}">
                            @foreach($period['slots'] as $option)
                                <button type="button" class="bk-slot" wire:key="mslot-{{ $option['starts_at'] }}" wire:click="pick('{{ $option['starts_at'] }}')"
                                        aria-pressed="{{ $slot === $option['starts_at'] ? 'true' : 'false' }}" @disabled($option['current'])
                                        @if($option['current']) title="{{ __('manager_booking.move.current') }}" @endif>
                                    <strong class="tabular" dir="ltr">{{ $option['time'] }}</strong>
                                    <span>{{ $option['current'] ? __('manager_booking.move.current') : $option['staff'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="form-actions">
        <button class="button button--ghost" type="button" wire:click="cancel">{{ __('ui.actions.back') }}</button>
        <button class="button" type="button" wire:click="move" wire:loading.attr="data-loading" wire:target="move" @disabled($slot === '')>
            <x-ui.icon name="clock" size="16" />{{ __('manager_booking.move.submit') }}
        </button>
    </div>
</section>
