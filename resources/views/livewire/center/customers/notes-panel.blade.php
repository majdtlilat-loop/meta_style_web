{{--
    Staff notes about this customer. Only the notes this viewer may read are
    here (CustomerPresenter decides, per visibility); none ever reaches a
    customer-facing surface, and a note's text is never audited.
--}}
<div class="stack">
    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    <div class="record-grid crm-notes">
        <x-ui.card :title="__('manager_customers.notes.title')">
            @if($notes === [])
                <x-ui.empty-state compact icon="file-text" :title="__('manager_customers.notes.none')" />
            @else
                <ul class="note-list crm-note-list">
                    @foreach($notes as $note)
                        <li wire:key="note-{{ $note['uuid'] }}">
                            <div class="crm-note">
                                <p class="prewrap">{{ $note['body'] }}</p>
                                <p class="crm-note__meta">
                                    <x-ui.status :tone="$note['visibility_tone']" :label="$note['visibility_label']" :dot="false" />
                                    <span>{{ $note['author'] ?? __('manager_customers.notes.unknown_author') }}</span>
                                    @if($note['when'])<time datetime="{{ $note['created_at'] }}" title="{{ $note['when'] }}">{{ $note['ago'] }}</time>@endif
                                </p>
                            </div>
                            @if($note['can_delete'])
                                <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="deleteNote('{{ $note['uuid'] }}')" wire:confirm="{{ __('manager_customers.notes.delete_confirm') }}" data-confirm-title="{{ __('manager_customers.notes.delete_title') }}" data-confirm-label="{{ __('ui.actions.delete') }}" data-confirm-tone="danger" aria-label="{{ __('manager_customers.notes.delete_title') }}" title="{{ __('manager_customers.notes.delete_title') }}"><x-ui.icon name="trash" /></button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        @if($canManage)
            <aside class="record-aside">
                <form class="card" wire:submit="addNote">
                    <header class="card__header"><div><h2>{{ __('manager_customers.notes.add') }}</h2></div></header>
                    <div class="card__body stack stack--sm">
                        <x-ui.field :label="__('manager_customers.notes.body')" for="note-body" name="noteBody" required>
                            <textarea id="note-body" rows="4" wire:model="noteBody" maxlength="{{ $maxLength }}" placeholder="{{ __('manager_customers.notes.placeholder') }}"></textarea>
                        </x-ui.field>
                        <p class="field-help"><x-ui.icon name="shield" size="12" /> {{ __('manager_customers.notes.no_health') }}</p>
                        <x-ui.field :label="__('manager_customers.notes.visibility')" for="note-visibility" name="noteVisibility">
                            <select id="note-visibility" wire:model="noteVisibility">
                                <option value="internal">{{ __('manager_customers.notes.all_staff') }}</option>
                                <option value="manager_only">{{ __('manager_customers.notes.managers_only') }}</option>
                            </select>
                        </x-ui.field>
                    </div>
                    <footer class="card__footer">
                        <button class="button button--sm" type="submit" wire:loading.attr="data-loading" wire:target="addNote"><x-ui.icon name="plus" size="16" />{{ __('manager_customers.notes.add') }}</button>
                    </footer>
                </form>
            </aside>
        @endif
    </div>
</div>
