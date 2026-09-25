{{-- The booking as agreed: facts, services as booked (snapshots), notes. --}}
@if($visit === 'active')
    <div class="notice" data-tone="info" role="status">
        <x-ui.icon name="user-check" />
        <p>{{ __('manager_booking.panel.visit_running') }}</p>
        @if($boardUrl)<a class="button button--secondary button--sm" href="{{ $boardUrl }}" wire:navigate>{{ __('manager_booking.panel.open_board') }}</a>@endif
    </div>
@endif

@if($booking['cancelled'])
    <div class="notice" data-tone="warning">
        <x-ui.icon name="x-circle" />
        <p>
            <strong>{{ __('manager_booking.panel.cancelled_on', ['when' => $booking['cancelled']['when'] ?? '—']) }}</strong>
            @if($booking['cancelled']['reason']){{ __('manager_booking.panel.cancel_reason', ['reason' => $booking['cancelled']['reason']]) }}@endif
            @if($booking['cancelled']['by'])<span class="cell-sub">{{ __('manager_booking.panel.cancelled_by', ['name' => $booking['cancelled']['by']]) }}</span>@endif
        </p>
    </div>
@endif

<dl class="kv-grid bk-facts">
    <div>
        <dt>{{ __('manager_booking.panel.when') }}</dt>
        <dd>{{ $booking['date_label'] }}<span class="cell-sub"><span dir="ltr" class="tabular">{{ $booking['time_label'] }}</span> · {{ $booking['duration_label'] }}</span></dd>
    </div>
    <div>
        <dt>{{ __('manager_booking.panel.branch') }}</dt>
        <dd>{{ $booking['branch']['name'] ?? '—' }}</dd>
    </div>
    <div>
        <dt>{{ __('manager_booking.panel.phone') }}</dt>
        <dd><span dir="ltr" class="tabular">{{ $booking['contact_phone'] ?? '—' }}</span>@if($booking['contact_masked'] && $booking['contact_phone'])<span class="cell-sub">{{ __('manager_booking.panel.phone_hidden') }}</span>@endif</dd>
    </div>
    <div>
        <dt>{{ __('manager_booking.panel.total') }}</dt>
        <dd><x-ui.money :minor="$booking['total']['amount']" :currency="$booking['total']['currency']" /></dd>
    </div>
    <div>
        <dt>{{ __('manager_booking.panel.booked_by') }}</dt>
        <dd>{{ $booking['created_by']['label'] ?: $booking['source_label'] }}<span class="cell-sub">{{ $booking['source_label'] }}</span></dd>
    </div>
    @if($booking['confirmed_label'])
        <div>
            <dt>{{ __('manager_booking.panel.confirmed') }}</dt>
            <dd>{{ $booking['confirmed_label'] }}</dd>
        </div>
    @endif
</dl>

@if($booking['customer_note'])
    <div class="bk-quote">
        <p class="bk-quote__label">{{ __('manager_booking.panel.customer_note') }}</p>
        <p class="prewrap">{{ $booking['customer_note'] }}</p>
    </div>
@endif

<section class="drawer-section" aria-labelledby="bk-p-services">
    <h3 id="bk-p-services">{{ __('manager_booking.panel.services') }}</h3>
    <ul class="bk-items">
        @foreach($booking['lines'] as $line)
            <li class="bk-item" wire:key="item-{{ $line['uuid'] }}">
                <div class="bk-item__main">
                    <strong>{{ $line['service'] }}</strong>
                    @if($line['variation'])<span class="tag">{{ $line['variation'] }}</span>@endif
                    <span class="cell-sub">
                        <span dir="ltr" class="tabular">{{ $line['time_label'] }}</span> · {{ $line['duration_label'] }}
                        @if($line['addons_label'] !== '') · + {{ $line['addons_label'] }}@endif
                        @if($line['rooms_label'] !== '') · {{ $line['rooms_label'] }}@endif
                    </span>
                    @if($line['note'])<span class="cell-sub prewrap">“{{ $line['note'] }}”</span>@endif
                    @if($line['rooms_label'] !== '' && $can['change_room'])
                        <button class="text-button" type="button" wire:click="startRoomChange('{{ $line['uuid'] }}', '{{ $line['room_default'] }}')">{{ __('manager_booking.panel.change_room') }}</button>
                    @endif
                </div>
                <div class="bk-item__staff">
                    <x-ui.icon name="user" size="16" />
                    <span>{{ $line['staff_name'] ?? __('manager_booking.calendar.unassigned') }}</span>
                    @if($line['named'])<span class="badge" title="{{ __('manager_booking.panel.requested_help') }}">{{ __('manager_booking.panel.requested') }}</span>@endif
                    @if($line['staff_inactive'])<span class="badge" data-tone="warning">{{ __('manager_booking.panel.inactive') }}</span>@endif
                    @if($can['reassign'])
                        <button class="text-button" type="button" wire:click="startReassign('{{ $line['uuid'] }}')">{{ __('manager_booking.panel.change_person') }}</button>
                    @endif
                </div>
                <x-ui.money class="bk-item__price" :minor="$line['price']['amount']" :currency="$line['price']['currency']" />
            </li>
        @endforeach
    </ul>
</section>

@if($can['view_notes'])
    <section class="drawer-section" aria-labelledby="bk-p-notes">
        <h3 id="bk-p-notes">{{ __('manager_booking.notes.title') }}</h3>
        @if($booking['note_rows'] === [])
            <p class="muted bk-hint">{{ __('manager_booking.notes.empty') }}</p>
        @else
            <ul class="note-list">
                @foreach($booking['note_rows'] as $note)
                    <li wire:key="note-{{ $note['uuid'] }}">
                        <div>
                            <p class="prewrap">{{ $note['body'] }}</p>
                            <span class="cell-sub">
                                <span class="tag" @if($note['visibility'] === 'manager_only') data-tone="warning" @endif>{{ $note['visibility_label'] }}</span>
                                {{ $note['when'] }}@if($note['author']) · {{ $note['author'] }}@endif
                            </span>
                        </div>
                        @if($can['manage_notes'])
                            <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="deleteNote('{{ $note['uuid'] }}')"
                                    wire:confirm="{{ __('manager_booking.notes.delete_confirm') }}" data-confirm-title="{{ __('manager_booking.notes.delete') }}" data-confirm-tone="danger" data-confirm-label="{{ __('manager_booking.notes.delete') }}"
                                    aria-label="{{ __('manager_booking.notes.delete') }}" title="{{ __('manager_booking.notes.delete') }}"><x-ui.icon name="trash" size="16" /></button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if($can['manage_notes'])
            <form class="bk-note-form" wire:submit="addNote">
                {{-- An explicit warning in front of the person about to type, not a filter afterwards (docs/15 §11). --}}
                <p class="bk-advisory"><x-ui.icon name="alert-triangle" size="16" />{{ $advisory }}</p>
                <x-ui.field :label="__('manager_booking.notes.body')" for="bk-p-note" name="noteBody" required>
                    <textarea id="bk-p-note" rows="2" maxlength="2000" wire:model="noteBody"></textarea>
                </x-ui.field>
                <div class="cluster cluster--between">
                    <div class="field field--inline">
                        <label for="bk-p-visibility">{{ __('manager_booking.notes.visible_to') }}</label>
                        <select id="bk-p-visibility" wire:model="noteVisibility">
                            <option value="internal">{{ __('manager_booking.notes.visibility.internal') }}</option>
                            <option value="manager_only">{{ __('manager_booking.notes.visibility.manager_only') }}</option>
                        </select>
                    </div>
                    <button class="button button--secondary button--sm" type="submit" wire:loading.attr="data-loading" wire:target="addNote">{{ __('manager_booking.notes.save') }}</button>
                </div>
            </form>
        @endif
    </section>
@endif
