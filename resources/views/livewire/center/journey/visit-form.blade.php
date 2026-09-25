{{--
    The one decision open on a stage: skip (with the customer's reason), hand
    on, reassign, swap a room, or a note. Choices are pre-narrowed by
    VisitOptions (active, at this branch, qualified; same kind of room) — the
    Actions still validate whatever is sent.
--}}
<form class="drawer-section stack stack--sm queue-form" wire:submit="{{ $formSubmit }}">
    <h4>{{ __('manager_visits.forms.'.$form.'.title') }}</h4>

    @if($form === 'skip')
        <x-ui.field :label="__('manager_visits.forms.skip.reason')" for="stage-reason" name="reason" required>
            <input id="stage-reason" type="text" wire:model="reason" maxlength="190" autocomplete="off">
        </x-ui.field>
    @elseif($form === 'handoff')
        <x-ui.field :label="__('manager_visits.forms.handoff.note')" for="stage-handoff" name="reason" :help="__('ui.states.optional')">
            <input id="stage-handoff" type="text" wire:model="reason" maxlength="190" autocomplete="off">
        </x-ui.field>
    @elseif($form === 'reassign')
        @if($choices === [])
            <x-ui.notice :message="__('manager_visits.forms.reassign.none')" tone="info" />
        @else
            <x-ui.field :label="__('manager_visits.forms.reassign.employee')" for="stage-employee" name="employee" required>
                <select id="stage-employee" wire:model="employee">
                    <option value="">{{ __('manager_queue.setup.choose') }}</option>
                    @foreach($choices as $option)
                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        @endif
    @elseif($form === 'swap')
        @if($choices === [])
            <x-ui.notice :message="__('manager_visits.forms.swap.none')" tone="info" />
        @else
            <div class="form-grid">
                <x-ui.field :label="__('manager_visits.forms.swap.from')" for="stage-swap-from" required>
                    <select id="stage-swap-from" wire:model.live="swapFrom">
                        <option value="">{{ __('manager_queue.setup.choose') }}</option>
                        @foreach($choices as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('manager_visits.forms.swap.to')" for="stage-swap-to" required>
                    <select id="stage-swap-to" wire:model="swapTo" @disabled($swapFrom === '')>
                        <option value="">{{ __('manager_queue.setup.choose') }}</option>
                        @foreach($choices as $option)
                            @if($option['uuid'] === $swapFrom)
                                @foreach($option['candidates'] as $candidate)
                                    <option value="{{ $candidate['uuid'] }}">{{ $candidate['name'] }}</option>
                                @endforeach
                            @endif
                        @endforeach
                    </select>
                </x-ui.field>
            </div>
        @endif
    @elseif($form === 'note')
        <x-ui.field :label="__('manager_visits.forms.note.body')" for="stage-note" name="body" required :help="__('manager_visits.notes.advisory')">
            <textarea id="stage-note" rows="3" wire:model="noteBody" maxlength="2000"></textarea>
        </x-ui.field>
        @if(count($visibilities) > 1)
            <div class="segmented" role="group" aria-label="{{ __('manager_visits.forms.note.visibility') }}">
                @foreach($visibilities as $visibility)
                    <button type="button" wire:click="$set('noteVisibility', '{{ $visibility }}')" aria-pressed="{{ $noteVisibility === $visibility ? 'true' : 'false' }}">{{ __('manager_visits.visibility.'.$visibility) }}</button>
                @endforeach
            </div>
        @endif
    @endif

    <div class="form-actions">
        <x-ui.button variant="ghost" wire:click="cancelForm">{{ __('ui.actions.cancel') }}</x-ui.button>
        <x-ui.button type="submit" wire:loading.attr="data-loading">{{ __('manager_visits.forms.'.$form.'.submit') }}</x-ui.button>
    </div>
</form>
