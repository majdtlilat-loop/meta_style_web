<form class="card card--flush" wire:submit="save" aria-labelledby="policies-title" novalidate>
    <header class="card__header">
        <div>
            <h2 id="policies-title">{{ __('manager_settings.policies.title') }}</h2>
        </div>
    </header>
    <div class="card__body stack">
        <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="$set('notice', '')" />

        <div @if (! $canManage) x-data x-readonly-fields @endif>
            <x-ui.lang-tabs id="policy-texts" :fields="$fields" :values="$values" :locales="$contentLocales" :primary="$primaryLocale" />
        </div>
        @unless ($canManage)
            <p class="field-help">{{ __('manager_settings.errors.forbidden') }}</p>
        @endunless

        <p class="field-help">
            {{ __('manager_settings.policies.note') }}
            @if ($appearanceUrl)
                <a href="{{ $appearanceUrl }}" wire:navigate>{{ __('manager_settings.policies.open_booking_appearance') }}</a>
            @endif
        </p>
    </div>
    @if ($canManage)
        <footer class="card__footer">
            <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save"><x-ui.icon name="save" size="16" />{{ __('manager_settings.policies.save') }}</button>
        </footer>
    @endif
</form>
