{{--
    The connection drawer. Credential inputs are WRITE-ONLY: never pre-filled,
    cleared by the component at the end of every request, and each shown only
    as configured / not configured. There is no field for a URL or a host.
--}}
<div>
    @if($open)
        <x-ui.drawer :title="__($editing ? 'manager_whatsapp.form.title_edit' : 'manager_whatsapp.form.title_new')" close="close" submit="save" class="wa-form" autocomplete="off">
            <div class="stack stack--sm">
                @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror

                <div class="form-grid">
                    <x-ui.field :label="__('manager_whatsapp.form.display_name')" for="wa-display-name" name="displayName" required class="field--full">
                        <input id="wa-display-name" type="text" wire:model="displayName" maxlength="120" required autocomplete="off">
                    </x-ui.field>
                    <x-ui.field :label="__('manager_whatsapp.form.display_phone_number')" for="wa-display-number" name="displayPhoneNumber">
                        <input id="wa-display-number" type="tel" dir="ltr" wire:model="displayPhoneNumber" maxlength="32" autocomplete="off" inputmode="tel">
                    </x-ui.field>
                    <x-ui.field :label="__('manager_whatsapp.form.phone_number_id')" for="wa-phone-number-id" name="phoneNumberId" required>
                        <input id="wa-phone-number-id" type="text" dir="ltr" class="mono" wire:model="phoneNumberId" maxlength="64" required autocomplete="off" spellcheck="false" inputmode="numeric">
                    </x-ui.field>
                    <x-ui.field :label="__('manager_whatsapp.form.business_account_id')" for="wa-business-id" name="businessAccountId" class="field--full">
                        <input id="wa-business-id" type="text" dir="ltr" class="mono" wire:model="businessAccountId" maxlength="64" autocomplete="off" spellcheck="false" inputmode="numeric">
                    </x-ui.field>
                </div>

                <fieldset class="field wa-secrets" x-data="{ replacing: {{ $startReplacing ? 'true' : 'false' }} }">
                    <legend>{{ __('manager_whatsapp.form.credentials') }}</legend>
                    <p class="field-help"><x-ui.icon name="lock" size="14" /> {{ __('manager_whatsapp.form.secret_note') }}</p>

                    <ul class="wa-credentials" role="list">
                        @foreach($fields as $field)
                            <li wire:key="wa-credential-state-{{ $field['name'] }}">
                                <span>{{ $field['label'] }}</span>
                                <x-ui.status :tone="$field['set'] ? 'success' : 'warning'" :dot="false" :label="__($field['set'] ? 'manager_whatsapp.credentials.configured' : 'manager_whatsapp.credentials.not_configured')" />
                            </li>
                        @endforeach
                    </ul>

                    @if($stored)
                        <button class="button button--secondary button--sm" type="button" x-show="! replacing" x-on:click="replacing = true; $nextTick(() => $root.querySelector('[data-secret]')?.focus())">
                            <x-ui.icon name="key" size="16" />{{ __('manager_whatsapp.form.replace') }}
                        </button>
                    @endif

                    <div class="stack stack--sm" x-show="replacing" @unless($startReplacing) x-cloak @endunless>
                        @foreach($fields as $field)
                            <x-ui.password :name="'credentials.'.$field['name']" :id="'wa-credential-'.$field['name']" :label="$field['label']" autocomplete="new-password"
                                wire:model="credentials.{{ $field['name'] }}" data-secret dir="ltr" spellcheck="false" maxlength="512" :help="$field['tip']" />
                        @endforeach
                        @error('credentials')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                        @if($stored)
                            <button class="text-button" type="button"
                                x-on:click="replacing = false; $root.querySelectorAll('[data-secret]').forEach((input) => { input.value = ''; input.dispatchEvent(new Event('input')); })">
                                {{ __('manager_whatsapp.form.keep') }}
                            </button>
                        @endif
                    </div>
                </fieldset>

                <label class="choice choice--switch">
                    <input type="checkbox" class="switch" role="switch" wire:model="enabled">
                    <span>{{ __('manager_whatsapp.form.enabled') }}</span>
                </label>
            </div>

            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="close">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save"><x-ui.icon name="save" size="16" />{{ __('manager_whatsapp.form.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
