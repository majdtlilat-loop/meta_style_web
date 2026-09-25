<form class="stack" wire:submit="save" novalidate>
    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="$set('notice', '')" />

    <x-ui.card :title="__('manager_settings.profile.title')">
        <div class="form-grid">
            <x-ui.field :label="__('manager_settings.profile.name')" for="profile-name" name="name" required class="form-grid__full">
                <input id="profile-name" type="text" wire:model="name" maxlength="190" required @disabled(! $canManage) autocomplete="organization">
            </x-ui.field>
            <x-ui.field :label="__('manager_settings.profile.contact_name')" for="profile-contact-name" name="contactName" :help="__('manager_settings.profile.contact_help')">
                <input id="profile-contact-name" type="text" wire:model="contactName" maxlength="190" @disabled(! $canManage) autocomplete="name">
            </x-ui.field>
            <x-ui.field :label="__('manager_settings.profile.contact_email')" for="profile-contact-email" name="contactEmail">
                <input id="profile-contact-email" type="email" wire:model="contactEmail" maxlength="190" dir="ltr" @disabled(! $canManage) autocomplete="email">
            </x-ui.field>
            <div class="form-grid__full">
                @if ($canManage)
                    <x-ui.phone number="phoneNumber" country="phoneCountry" :label="__('manager_settings.profile.contact_phone')" id="profile-contact-phone" />
                @else
                    <x-ui.field :label="__('manager_settings.profile.contact_phone')" for="profile-contact-phone-ro">
                        <input id="profile-contact-phone-ro" type="text" value="{{ $phoneNumber }}" dir="ltr" disabled>
                    </x-ui.field>
                @endif
            </div>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('manager_settings.profile.regional_title')">
        <div class="form-grid">
            <x-ui.field :label="__('manager_settings.profile.timezone')" for="profile-timezone" name="timezone" :help="__('manager_settings.profile.timezone_help')">
                <select id="profile-timezone" wire:model="timezone" dir="ltr" @disabled(! $canManage)>
                    <option value="">{{ __('manager_settings.profile.timezone_unset') }}</option>
                    @foreach ($timezones as $zone)
                        <option value="{{ $zone }}">{{ $zone }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('manager_settings.profile.currency')" for="profile-currency" name="currency" :help="$currencyLocked ? __('manager_settings.profile.currency_locked_help') : __('manager_settings.profile.currency_help')">
                @if ($currencyLocked || ! $canManage)
                    <div class="input-affix">
                        <input id="profile-currency" type="text" value="{{ $currentCurrency }}" dir="ltr" disabled>
                        @if ($currencyLocked)<span class="input-affix__suffix" title="{{ __('manager_settings.profile.currency_locked') }}"><x-ui.icon name="lock" size="14" /></span>@endif
                    </div>
                @else
                    <select id="profile-currency" wire:model="currency" dir="ltr">
                        @if ($currency === '')<option value="">{{ __('manager_settings.profile.currency_default', ['code' => $currentCurrency]) }}</option>@endif
                        @foreach ($currencies as $option)
                            <option value="{{ $option['code'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                @endif
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('manager_settings.profile.address_title')">
        <dl class="summary-list">
            <div>
                <dt>{{ __('manager_settings.profile.address') }}</dt>
                <dd dir="ltr">
                    @if ($address)
                        <a href="{{ $address }}" target="_blank" rel="noopener">{{ $address }}</a>
                    @else
                        {{ __('manager_settings.profile.address_unavailable') }}
                    @endif
                </dd>
            </div>
        </dl>
        <p class="field-help">{{ __('manager_settings.profile.address_note') }}</p>
    </x-ui.card>

    @if ($canManage)
        <div class="form-actions">
            <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save"><x-ui.icon name="save" size="16" />{{ __('manager_settings.profile.save') }}</button>
        </div>
    @endif
</form>
