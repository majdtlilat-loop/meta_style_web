{{--
    One visit on the floor board. `$card` is JourneyBoardView::cards(): times on
    the branch clock, live minutes, and the `can` flags for its buttons.
--}}
<article class="visit-card" data-group="{{ $card['group'] }}" wire:key="visit-{{ $card['key'] }}">
    <header class="visit-card__head">
        <span class="visit-card__time tabular" dir="ltr">{{ $card['time'] ?? '—' }}</span>
        @if($card['source'] === 'walk_in')
            <span class="badge" data-tone="neutral">{{ __('manager_visits.card.walk_in') }}</span>
        @elseif($card['reference'])
            <span class="badge" dir="ltr">{{ $card['reference'] }}</span>
        @endif
        @if($card['late_minutes'] !== null)
            <span class="ticket-card__timer" data-tone="{{ $card['late_tone'] }}"><x-ui.icon name="clock" size="14" />{{ __('manager_visits.card.late', ['minutes' => $card['late_minutes']]) }}</span>
        @elseif($card['waiting_minutes'] !== null)
            <span class="ticket-card__timer" data-tone="{{ $card['waiting_tone'] }}"><x-ui.icon name="clock" size="14" />{{ __('manager_visits.card.waiting', ['minutes' => $card['waiting_minutes']]) }}</span>
        @endif
    </header>

    <p class="ticket-card__customer">
        @if($card['journey_uuid'])
            <button type="button" class="text-button" wire:click="openVisit('{{ $card['journey_uuid'] }}')">{{ $card['customer'] ?? __('manager_queue.card.guest') }}</button>
        @else
            {{ $card['customer'] ?? __('manager_queue.card.guest') }}
        @endif
    </p>

    @if($card['current'])
        <div class="visit-card__now">
            <p class="ticket-card__meta"><x-ui.icon name="play" size="14" /><strong>{{ $card['current']['service'] }}</strong>@if($card['current']['employee']) · {{ $card['current']['employee'] }}@endif</p>
            @if($card['current']['elapsed'] !== null)
                <div class="progress visit-card__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $card['current']['percent'] }}" aria-label="{{ __('manager_visits.card.elapsed', ['minutes' => $card['current']['elapsed'], 'expected' => $card['current']['expected']]) }}" @if($card['current']['overrun']) data-tone="warning" @endif>
                    <span class="progress__bar" style="inline-size: {{ $card['current']['percent'] }}%"></span>
                </div>
                <p class="cell-sub">{{ __('manager_visits.card.elapsed', ['minutes' => $card['current']['elapsed'], 'expected' => $card['current']['expected']]) }}</p>
            @endif
        </div>
    @elseif($card['next'])
        <p class="ticket-card__meta"><x-ui.icon name="arrow-right" size="14" />{{ $card['next']['service'] }}@if($card['next']['employee']) · {{ $card['next']['employee'] }}@endif</p>
    @elseif($card['services'] !== [])
        <p class="ticket-card__meta"><x-ui.icon name="scissors" size="14" />{{ implode(' · ', $card['services']) }}</p>
    @endif

    @if($card['progress']['total'] > 1)
        <p class="cell-sub">{{ __('manager_visits.card.progress', ['done' => $card['progress']['done'], 'total' => $card['progress']['total']]) }}</p>
    @endif

    <footer class="ticket-card__actions">
        @unless($readOnly)
            @if($card['can']['check_in'])
                <x-ui.button size="sm" icon="user-check" wire:click="checkIn('{{ $card['appointment_uuid'] }}')" wire:loading.attr="data-loading" wire:target="checkIn('{{ $card['appointment_uuid'] }}')">{{ __('manager_visits.actions.check_in') }}</x-ui.button>
            @endif
            @if($card['can']['start'])
                <x-ui.button size="sm" icon="play" wire:click="start('{{ $card['next']['uuid'] }}')" wire:loading.attr="data-loading" wire:target="start('{{ $card['next']['uuid'] }}')">{{ __('manager_visits.actions.start') }}</x-ui.button>
            @endif
            @if($card['can']['finish'])
                <x-ui.button size="sm" icon="check" wire:click="finish('{{ $card['current']['uuid'] }}')" wire:loading.attr="data-loading" wire:target="finish('{{ $card['current']['uuid'] }}')">{{ __('manager_visits.actions.finish') }}</x-ui.button>
            @endif
        @endunless
        <span class="ticket-card__spacer"></span>
        @if($canCheckout && $card['can']['checkout'] && $card['group'] !== 'abandoned')
            <a class="icon-button icon-button--sm" href="{{ route('center.pos', ['journey' => $card['journey_uuid']]) }}" wire:navigate title="{{ __('manager_visits.actions.checkout') }}" aria-label="{{ __('manager_visits.actions.checkout') }}"><x-ui.icon name="pos" size="16" /></a>
        @endif
        @if($card['journey_uuid'])
            <button type="button" class="icon-button icon-button--sm" wire:click="openVisit('{{ $card['journey_uuid'] }}')" title="{{ __('manager_visits.actions.details') }}" aria-label="{{ __('manager_visits.actions.details') }}"><x-ui.icon name="more" size="16" /></button>
        @endif
    </footer>
</article>
