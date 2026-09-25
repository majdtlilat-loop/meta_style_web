<div>
    @if($person === null)
        <x-ui.drawer :title="__('manager_staff.profile.missing_title')" close="close" size="lg">
            <x-ui.empty-state icon="user-x" :title="__('manager_staff.profile.missing_title')" />
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="close">{{ __('ui.actions.close') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @else
        <x-ui.drawer :title="$person['name']" close="close" :submit="$editing ? 'save' : null" size="lg" class="staff-profile">
            <div class="staff-profile__identity">
                <span class="avatar avatar--lg" aria-hidden="true">{{ $person['initial'] }}</span>
                <div class="staff-profile__badges">
                    <x-ui.status :value="$person['status']" :label="$person['active'] ? __('ui.states.active') : __('ui.states.inactive')" />
                    <x-ui.status :tone="$person['login_tone']" :label="__('manager_staff.login.'.$person['login'])" :dot="false" />
                    @if($person['is_owner'])<span class="chip">{{ __('manager_staff.badges.owner') }}</span>@endif
                    @if($person['is_self'])<span class="chip chip--muted">{{ __('manager_staff.badges.you') }}</span>@endif
                </div>
            </div>

            <div class="segmented segmented--scroll staff-profile__tabs" role="tablist" aria-label="{{ __('manager_staff.profile.sections') }}">
                @foreach(['overview', 'access', 'services', 'time_off'] as $section)
                    <button type="button" role="tab" id="staff-tab-{{ $section }}" aria-selected="{{ $tab === $section ? 'true' : 'false' }}" aria-pressed="{{ $tab === $section ? 'true' : 'false' }}" wire:click="setTab('{{ $section }}')">{{ __('manager_staff.profile.tabs.'.$section) }}</button>
                @endforeach
            </div>

            <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

            <div class="staff-profile__panel" role="tabpanel" aria-labelledby="staff-tab-{{ $tab }}">
                @if($tab === 'overview')
                    @if($editing)
                        <div class="stack">
                            @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
                            <x-ui.lang-tabs id="staff-edit-name" :locales="$locales" :primary="$primaryLocale" :values="['name' => $name]" :fields="[
                                ['name' => 'name', 'label' => __('manager_staff.fields.name'), 'max' => 190, 'required' => true],
                            ]" />
                            @error('name')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                            <fieldset class="field">
                                <legend>{{ __('manager_staff.fields.branches') }}</legend>
                                <div class="chip-select">
                                    @foreach($branchOptions as $option)
                                        <label class="chip-toggle" wire:key="edit-branch-{{ $option['id'] }}"><input type="checkbox" value="{{ $option['id'] }}" wire:model="branchIds"><span>{{ $option['name'] }}@if($option['archived']) · {{ __('ui.states.archived') }}@endif</span></label>
                                    @endforeach
                                </div>
                                @error('branchIds')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                            </fieldset>
                        </div>
                    @else
                        <dl class="kv-grid">
                            <div><dt>{{ __('manager_staff.fields.email') }}</dt><dd dir="ltr">{{ $person['contact_email'] ?? '—' }}</dd></div>
                            <div><dt>{{ __('phone_field.label') }}</dt><dd>@if($person['contact_phone'])<span class="flagged" dir="ltr">@if($person['phone_flag'])<img class="flag-icon" src="{{ $person['phone_flag'] }}" alt="" width="20" height="15">@endif<span class="tabular">{{ $person['contact_phone'] }}</span></span>@else — @endif</dd></div>
                            <div><dt>{{ __('manager_staff.fields.branches') }}</dt><dd>{{ $person['branches'] === [] ? __('manager_staff.staff.no_branch') : implode(', ', $person['branches']) }}</dd></div>
                            <div><dt>{{ __('manager_staff.fields.roles') }}</dt><dd>{{ $person['roles'] === [] ? '—' : implode(', ', $person['roles']) }}</dd></div>
                            <div><dt>{{ __('manager_staff.fields.last_login') }}</dt><dd>{{ $person['last_login_at'] ? $person['last_login_at']->diffForHumans() : __('manager_staff.profile.never') }}</dd></div>
                            <div><dt>{{ __('manager_staff.fields.added') }}</dt><dd>{{ $person['created_at']?->translatedFormat('j M Y') ?? '—' }}</dd></div>
                        </dl>

                        @if($person['can']['deactivate'] || $person['can']['reactivate'])
                            <section class="danger-zone staff-profile__lifecycle">
                                @if($person['can']['deactivate'])
                                    <div class="cluster cluster--between">
                                        <div>
                                            <strong>{{ __('manager_staff.lifecycle.deactivate_title') }}</strong>
                                            <p class="muted">{{ __('manager_staff.lifecycle.deactivate_hint') }}</p>
                                        </div>
                                        <button class="button button--danger-soft button--sm" type="button" wire:click="askStatus('deactivate')"><x-ui.icon name="user-x" size="16" />{{ __('ui.actions.deactivate') }}</button>
                                    </div>
                                @else
                                    <div class="cluster cluster--between">
                                        <strong>{{ __('manager_staff.lifecycle.reactivate_title') }}</strong>
                                        <button class="button button--secondary button--sm" type="button" wire:click="askStatus('reactivate')"><x-ui.icon name="user-check" size="16" />{{ __('ui.actions.activate') }}</button>
                                    </div>
                                @endif
                            </section>
                        @elseif($person['is_owner'] || $person['is_self'])
                            <p class="field-help staff-profile__protected"><x-ui.icon name="shield" size="14" class="inline" /> {{ $person['is_owner'] ? __('manager_staff.lifecycle.owner_protected') : __('manager_staff.lifecycle.self_protected') }}</p>
                        @endif
                    @endif
                @elseif($tab === 'access')
                    <livewire:center.staff.access :uuid="$person['uuid']" :key="'staff-access-'.$person['uuid']" />
                @elseif($tab === 'services')
                    <livewire:center.staff.services :uuid="$person['uuid']" :key="'staff-services-'.$person['uuid']" />
                @else
                    <livewire:center.resources.availability-blocks :employee="$person['uuid']" :key="'staff-time-off-'.$person['uuid']" />
                @endif
            </div>

            <x-slot:footer>
                @if($tab === 'overview' && $editing)
                    <button class="button button--secondary" type="button" wire:click="cancelEdit">{{ __('ui.actions.cancel') }}</button>
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('ui.actions.save_changes') }}</button>
                @else
                    <button class="button button--secondary" type="button" wire:click="close">{{ __('ui.actions.close') }}</button>
                    @if($tab === 'overview' && $person['can']['edit'])
                        <button class="button" type="button" wire:click="startEdit"><x-ui.icon name="edit" size="16" />{{ __('ui.actions.edit') }}</button>
                    @endif
                @endif
            </x-slot:footer>
        </x-ui.drawer>

        @if($confirm !== null)
            <x-ui.modal close="closeConfirm" submit="setStatus"
                :title="$confirm === 'deactivate' ? __('manager_staff.lifecycle.deactivate_confirm_title', ['name' => $person['name']]) : __('manager_staff.lifecycle.reactivate_confirm_title', ['name' => $person['name']])"
                :description="$confirm === 'deactivate' ? __('manager_staff.lifecycle.deactivate_confirm_body') : __('manager_staff.lifecycle.reactivate_confirm_body')"
                :icon="$confirm === 'deactivate' ? 'user-x' : 'user-check'" :tone="$confirm === 'deactivate' ? 'danger' : null">
                @error('confirm')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
                <x-slot:footer>
                    <button class="button button--secondary" type="button" wire:click="closeConfirm">{{ __('ui.actions.cancel') }}</button>
                    <button class="button {{ $confirm === 'deactivate' ? 'button--danger' : '' }}" type="submit" wire:loading.attr="data-loading" wire:target="setStatus">{{ $confirm === 'deactivate' ? __('ui.actions.deactivate') : __('ui.actions.activate') }}</button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endif
</div>
