<div class="stack">
    <x-ui.page-header :title="__('sadmin_shell.account.title')" />
    <x-ui.flash />

    <div class="record-grid">
        <div class="stack">
            <x-ui.card :title="__('sadmin_shell.account.profile')">
                <dl class="kv-grid">
                    <div><dt>{{ __('sadmin_shell.account.name') }}</dt><dd>{{ $user->name }}</dd></div>
                    <div><dt>{{ __('sadmin_shell.account.email') }}</dt><dd dir="ltr">{{ $email }}</dd></div>
                    <div><dt>{{ __('sadmin_shell.account.roles') }}</dt><dd>{{ $roles === [] ? '—' : implode(', ', $roles) }}</dd></div>
                    <div><dt>{{ __('sadmin_shell.account.last_login') }}</dt><dd>{{ $user->last_login_at?->translatedFormat('j M Y, H:i') ?? '—' }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card :title="__('sadmin_shell.account.security')">
                <dl class="summary-list">
                    <div>
                        <dt>{{ __('sadmin_shell.account.mfa') }}</dt>
                        <dd>
                            <x-ui.status :value="$user->hasConfirmedMfa() ? 'enabled' : 'disabled'" :label="$user->hasConfirmedMfa() ? __('sadmin_shell.account.mfa_on') : __('sadmin_shell.account.mfa_off')" />
                            @unless($mfaRequired)<span class="cell-sub">{{ __('sadmin_shell.account.mfa_policy_off') }}</span>@endunless
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('sadmin_shell.account.sound') }}</dt>
                        <dd>
                            <button type="button" class="button button--secondary button--sm" data-sound-toggle
                                    data-label-mute="{{ __('sadmin_shell.bell.mute') }}" data-label-unmute="{{ __('sadmin_shell.bell.unmute') }}"
                                    aria-pressed="false" aria-label="{{ __('sadmin_shell.bell.mute') }}" wire:ignore>
                                <span class="sound-on"><x-ui.icon name="volume" size="16" /></span>
                                <span class="sound-off"><x-ui.icon name="volume-off" size="16" /></span>
                                <span class="sound-on">{{ __('sadmin_shell.bell.mute') }}</span>
                                <span class="sound-off">{{ __('sadmin_shell.bell.unmute') }}</span>
                            </button>
                            <span class="cell-sub">{{ __('sadmin_shell.account.sound_help') }}</span>
                        </dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>

        <div class="stack">
            <x-ui.card :title="__('sadmin_shell.account.password')">
                <form class="stack stack--sm" wire:submit="changePassword" novalidate>
                    <x-ui.password name="currentPassword" wire:model="currentPassword" :label="__('sadmin_shell.account.current_password')" autocomplete="current-password" required />
                    <x-ui.password name="newPassword" wire:model="newPassword" :label="__('sadmin_shell.account.new_password')" autocomplete="new-password" required />
                    <p class="field-help">{{ __('platform_auth.password_rules') }}</p>
                    <x-ui.password name="newPasswordConfirmation" wire:model="newPasswordConfirmation" :label="__('sadmin_shell.account.confirm_password')" autocomplete="new-password" required />
                    <div class="cluster">
                        <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="changePassword">{{ __('ui.actions.save') }}</button>
                    </div>
                </form>
            </x-ui.card>

            <x-ui.card :title="__('sadmin_shell.account.permissions')">
                <ul class="chip-list">
                    @foreach($permissions as $code)
                        <li class="chip">{{ __('platform_permissions.codes.'.str_replace('.', '_', $code)) }}</li>
                    @endforeach
                </ul>
            </x-ui.card>
        </div>
    </div>
</div>
