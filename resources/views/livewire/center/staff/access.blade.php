<div class="stack staff-access">
    @if($person === null)
        <x-ui.empty-state compact icon="user-x" :title="__('manager_staff.profile.missing_title')" />
    @else
        @if($activationUrl)
            <div class="secret-card" role="status">
                <div class="secret-card__head">
                    <span class="secret-card__icon" aria-hidden="true"><x-ui.icon name="key" /></span>
                    <div>
                        <strong>{{ __('manager_staff.activation.card_title', ['name' => $person['name']]) }}</strong>
                        <p>{{ __('manager_staff.activation.card_body') }}</p>
                    </div>
                </div>
                <div class="copy-field">
                    <code dir="ltr">{{ $activationUrl }}</code>
                    <button class="button button--secondary button--sm" type="button" data-copy="{{ $activationUrl }}" data-copied="{{ __('ui.actions.copied') }}"><x-ui.icon name="copy" size="16" />{{ __('ui.actions.copy_link') }}</button>
                </div>
                <button class="text-button" type="button" wire:click="dismissToken">{{ __('manager_staff.activation.done') }}</button>
            </div>
        @endif

        @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
        @error('login')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror

        <section class="drawer-section">
            <h3>{{ __('manager_staff.access.login_title') }}</h3>
            @if($person['login'] === 'none')
                @if($person['can']['grant_login'])
                    <div class="stack stack--sm">
                        <x-ui.phone number="phone" country="phoneCountry" id="access-phone" :label="__('phone_field.login_label')" required />
                        <x-ui.field :label="__('manager_staff.fields.email_optional')" for="access-email" name="email" :help="__('manager_staff.staff.email_help')">
                            <input id="access-email" type="email" dir="ltr" wire:model="email" autocomplete="off" maxlength="190">
                        </x-ui.field>
                        @if($roleOptions !== [])
                            <fieldset class="field">
                                <legend>{{ __('manager_staff.fields.roles') }}</legend>
                                <div class="chip-select">
                                    @foreach($roleOptions as $option)
                                        <label class="chip-toggle" wire:key="grant-role-{{ $option['id'] }}"><input type="checkbox" value="{{ $option['id'] }}" wire:model="roleIds"><span>{{ $option['name'] }}</span></label>
                                    @endforeach
                                </div>
                                @error('roleIds')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                            </fieldset>
                        @endif
                        <div class="cluster cluster--end">
                            <button class="button" type="button" wire:click="grantLogin" wire:loading.attr="data-loading" wire:target="grantLogin"><x-ui.icon name="key" size="16" />{{ __('manager_staff.access.grant') }}</button>
                        </div>
                    </div>
                @else
                    <p class="muted">{{ __('manager_staff.access.no_login') }}</p>
                @endif
            @else
                <div class="staff-access__state" data-state="{{ $person['login'] }}">
                    <x-ui.status :tone="$person['login_tone']" :label="__('manager_staff.login.'.$person['login'])" :dot="false" />
                    <p class="muted">{{ __('manager_staff.access.state.'.$person['login']) }}</p>
                </div>
                @if($person['can']['reissue'])
                    <div class="cluster">
                        <button class="button button--secondary button--sm" type="button" wire:click="reissue" wire:loading.attr="data-loading" wire:target="reissue"
                            wire:confirm="{{ __('manager_staff.access.reissue_confirm') }}" data-confirm-title="{{ __('manager_staff.access.reissue') }}"><x-ui.icon name="refresh" size="16" />{{ __('manager_staff.access.reissue') }}</button>
                    </div>
                @endif
            @endif
        </section>

        @if($person['can']['contact'])
            <section class="drawer-section" wire:key="access-contact">
                <div class="cluster cluster--tight">
                    <h3>{{ __('manager_staff.access.contact_title') }}</h3>
                    <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_staff.access.contact_help') }}" aria-label="{{ __('manager_staff.access.contact_help') }}"><x-ui.icon name="info" size="16" /></span>
                </div>
                <div class="stack stack--sm">
                    <x-ui.phone number="contactPhone" country="contactPhoneCountry" id="access-contact-phone" :label="__('phone_field.login_label')" required />
                    <x-ui.field :label="__('manager_staff.fields.email_optional')" for="access-contact-email" name="contactEmail">
                        <input id="access-contact-email" type="email" dir="ltr" wire:model="contactEmail" autocomplete="off" maxlength="190">
                    </x-ui.field>
                    <div class="cluster cluster--end">
                        <button class="button button--secondary button--sm" type="button" wire:click="saveContact" wire:loading.attr="data-loading" wire:target="saveContact">{{ __('manager_staff.access.save_contact') }}</button>
                    </div>
                </div>
            </section>
        @endif

        @if($person['login'] !== 'none')
            @if($person['can']['access'])
                <section class="drawer-section">
                    <h3>{{ __('manager_staff.fields.roles') }}</h3>
                    <p class="field-help">{{ __('manager_staff.staff.roles_help') }}</p>
                    @if($roleOptions === [])
                        <p class="muted">{{ __('manager_staff.staff.no_roles_available') }}</p>
                    @else
                        <div class="chip-select" role="group" aria-label="{{ __('manager_staff.fields.roles') }}">
                            @foreach($roleOptions as $option)
                                <label class="chip-toggle" wire:key="access-role-{{ $option['id'] }}"><input type="checkbox" value="{{ $option['id'] }}" wire:model="roleIds"><span>{{ $option['name'] }}</span></label>
                            @endforeach
                        </div>
                    @endif
                    @error('roleIds')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                    <div class="cluster cluster--end">
                        <button class="button button--sm" type="button" wire:click="saveRoles" wire:loading.attr="data-loading" wire:target="saveRoles">{{ __('manager_staff.access.save_roles') }}</button>
                    </div>
                </section>

                <section class="drawer-section">
                    <div class="cluster cluster--tight">
                        <h3>{{ __('manager_staff.access.scope_title') }}</h3>
                        <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_staff.access.scope_help') }}" aria-label="{{ __('manager_staff.access.scope_help') }}"><x-ui.icon name="info" size="16" /></span>
                    </div>
                    <div class="choice-grid staff-access__scope" role="radiogroup" aria-label="{{ __('manager_staff.access.scope_title') }}">
                        <label class="choice">
                            <input type="radio" name="scope-mode" value="all" wire:model.live="scopeMode" @disabled(! $viewerUnrestricted)>
                            <span>{{ __('ui.fields.all_branches') }}<small class="muted">{{ $viewerUnrestricted ? __('manager_staff.access.scope_all_help') : __('manager_staff.access.scope_all_locked') }}</small></span>
                        </label>
                        <label class="choice">
                            <input type="radio" name="scope-mode" value="some" wire:model.live="scopeMode">
                            <span>{{ __('manager_staff.access.scope_some') }}</span>
                        </label>
                    </div>
                    @if($scopeMode === 'some')
                        <div class="chip-select" role="group" aria-label="{{ __('manager_staff.fields.branches') }}">
                            @foreach($branchOptions as $option)
                                <label class="chip-toggle" wire:key="scope-branch-{{ $option['id'] }}"><input type="checkbox" value="{{ $option['id'] }}" wire:model="scopeIds"><span>{{ $option['name'] }}@if($option['archived']) · {{ __('ui.states.archived') }}@endif</span></label>
                            @endforeach
                        </div>
                    @endif
                    @error('scopeIds')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                    <div class="cluster cluster--end">
                        <button class="button button--sm" type="button" wire:click="saveScope" wire:loading.attr="data-loading" wire:target="saveScope">{{ __('manager_staff.access.save_scope') }}</button>
                    </div>
                </section>
            @else
                <section class="drawer-section">
                    <dl class="kv-grid">
                        <div><dt>{{ __('manager_staff.fields.roles') }}</dt><dd>{{ $person['roles'] === [] ? '—' : implode(' · ', $person['roles']) }}</dd></div>
                        <div><dt>{{ __('manager_staff.access.scope_title') }}</dt><dd>{{ $person['all_branches'] ? __('ui.fields.all_branches') : trans_choice('manager_staff.access.scope_count', count($person['scope_ids']), ['count' => count($person['scope_ids'])]) }}</dd></div>
                    </dl>
                    <p class="field-help"><x-ui.icon name="lock" size="14" class="inline" /> {{ $person['is_owner'] ? __('manager_staff.access.locked_owner') : ($person['is_self'] ? __('manager_staff.access.locked_self') : __('manager_staff.access.locked_other')) }}</p>
                </section>
            @endif
        @endif
    @endif
</div>
