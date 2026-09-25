{{--
    One ticket on the board. `$card` comes from QueueBoardView::cards(): the
    buttons it shows are the `can` flags decided there (state map + grants +
    entitlements). Staff surface — the customer is named, never contacted.
--}}
<article class="ticket-card" data-state="{{ $card['state'] }}" data-priority="{{ $card['priority_level'] }}" wire:key="ticket-{{ $card['uuid'] }}">
    <header class="ticket-card__head">
        <button type="button" class="ticket-card__number" wire:click="openTicket('{{ $card['uuid'] }}')" title="{{ __('manager_queue.actions.details') }}" dir="ltr">{{ $card['number'] }}</button>
        <div class="ticket-card__badges">
            @if($card['priority_level'] !== 'normal')
                <span class="badge" data-tone="{{ $card['priority_level'] === 'urgent' ? 'danger' : 'warning' }}"><x-ui.icon name="flag" size="12" />{{ __('manager_queue.priority.'.$card['priority_level']) }}</span>
            @endif
            @if($card['walk_in'])
                <span class="badge" data-tone="neutral">{{ __('manager_queue.card.walk_in') }}</span>
            @endif
        </div>
        @if($card['elapsed_minutes'] !== null)
            <span class="ticket-card__timer" data-tone="{{ $card['elapsed_tone'] }}" title="{{ __('manager_queue.card.issued_at', ['time' => $card['issued']]) }}">
                <x-ui.icon name="clock" size="14" />{{ __('manager_queue.timer.'.$card['state'], ['minutes' => $card['elapsed_minutes']]) }}
            </span>
        @elseif($card['closed'])
            <span class="ticket-card__timer">{{ $card['closed'] }}</span>
        @endif
    </header>

    <p class="ticket-card__customer">{{ $card['customer'] ?? __('manager_queue.card.guest') }}</p>

    @if($card['service'] || $card['employee'])
        <p class="ticket-card__meta"><x-ui.icon name="scissors" size="14" />{{ $card['service'] }}@if($card['employee']) · {{ $card['employee'] }}@endif</p>
    @endif

    @if($card['destination'] || $card['department'])
        <p class="ticket-card__meta">
            <x-ui.icon name="map-pin" size="14" />
            @if($card['destination'])<strong dir="ltr">{{ $card['destination']['code'] }}</strong> {{ $card['destination']['name'] }}@else{{ $card['department'] }}@endif
        </p>
    @endif

    @if($card['call_count'] > 1 || $card['skip_count'] > 0 || $card['hold_reason'] || ($card['state'] === 'cancelled'))
        <p class="chip-list ticket-card__chips">
            @if($card['call_count'] > 1)<span class="chip">{{ __('manager_queue.card.calls', ['count' => $card['call_count']]) }}</span>@endif
            @if($card['skip_count'] > 0)<span class="chip">{{ __('manager_queue.card.skips', ['count' => $card['skip_count']]) }}</span>@endif
            @if($card['hold_reason'])<span class="chip chip--muted ticket-card__reason" title="{{ $card['hold_reason'] }}">{{ $card['hold_reason'] }}</span>@endif
            @if($card['state'] === 'cancelled')<x-ui.status value="cancelled" :label="__('labels.ticket_state.cancelled')" />@endif
        </p>
    @endif

    <footer class="ticket-card__actions">
        @unless($readOnly)
            @if($card['can']['call'])
                <x-ui.button size="sm" icon="megaphone" wire:click="callTicket('{{ $card['uuid'] }}')" wire:loading.attr="data-loading" wire:target="callTicket('{{ $card['uuid'] }}')">{{ __('manager_queue.actions.call') }}</x-ui.button>
            @endif
            @if($card['can']['resume'])
                <x-ui.button size="sm" icon="undo" wire:click="resume('{{ $card['uuid'] }}')" wire:loading.attr="data-loading" wire:target="resume('{{ $card['uuid'] }}')">{{ __('manager_queue.actions.resume') }}</x-ui.button>
            @endif
            @if($card['can']['start'])
                <x-ui.button size="sm" :variant="$card['state'] === 'called' ? 'primary' : 'secondary'" icon="play" wire:click="start('{{ $card['uuid'] }}')" wire:loading.attr="data-loading" wire:target="start('{{ $card['uuid'] }}')">{{ __('manager_queue.actions.start') }}</x-ui.button>
            @endif
            @if($card['can']['finish'])
                <x-ui.button size="sm" icon="check" wire:click="finish('{{ $card['uuid'] }}')" wire:loading.attr="data-loading" wire:target="finish('{{ $card['uuid'] }}')">{{ __('manager_queue.actions.finish') }}</x-ui.button>
            @endif
            @if($card['can']['recall'])
                <x-ui.button size="sm" variant="secondary" icon="megaphone" wire:click="callTicket('{{ $card['uuid'] }}')" wire:loading.attr="data-loading" wire:target="callTicket('{{ $card['uuid'] }}')">{{ __('manager_queue.actions.recall') }}</x-ui.button>
            @endif
            @if($card['can']['skip'])
                <x-ui.button size="sm" variant="ghost" wire:click="skip('{{ $card['uuid'] }}')" wire:loading.attr="data-loading" wire:target="skip('{{ $card['uuid'] }}')" title="{{ __('manager_queue.actions.no_answer_hint') }}">{{ __('manager_queue.actions.no_answer') }}</x-ui.button>
            @elseif($card['can']['hold'])
                <x-ui.button size="sm" variant="ghost" icon="pause" wire:click="hold('{{ $card['uuid'] }}')" wire:loading.attr="data-loading" wire:target="hold('{{ $card['uuid'] }}')">{{ __('manager_queue.actions.hold') }}</x-ui.button>
            @endif
        @endunless
        <span class="ticket-card__spacer"></span>
        @if($card['can']['print'])
            <a class="icon-button icon-button--sm" href="{{ route('center.queue.ticket', ['uuid' => $card['uuid']]) }}" target="_blank" rel="noopener" title="{{ __('manager_queue.actions.print') }}" aria-label="{{ __('manager_queue.actions.print_number', ['number' => $card['number']]) }}"><x-ui.icon name="print" size="16" /></a>
        @endif
        <button type="button" class="icon-button icon-button--sm" wire:click="openTicket('{{ $card['uuid'] }}')" title="{{ __('manager_queue.actions.details') }}" aria-label="{{ __('manager_queue.actions.details_for', ['number' => $card['number']]) }}"><x-ui.icon name="more" size="16" /></button>
    </footer>
</article>
