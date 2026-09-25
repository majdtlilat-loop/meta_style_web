<form class="card card--flush" id="content-languages" wire:submit="saveLanguages" aria-labelledby="content-languages-title">
    <header class="card__header">
        <div>
            <h2 id="content-languages-title">{{ __('manager_settings.languages.title') }}</h2>
        </div>
    </header>
    <div class="card__body stack">
        <x-ui.notice :message="$notice" tone="success" dismiss="$set('notice', '')" />

        <fieldset class="field">
            <legend>
                {{ __('manager_settings.languages.enabled') }}
                <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_settings.languages.preservation_note') }}" aria-label="{{ __('manager_settings.languages.preservation_note') }}"><x-ui.icon name="info" size="16" /></span>
            </legend>
            <div class="language-card-grid">
                @foreach ($options as $option)
                    <div class="language-option" @if ($option['enabled']) data-enabled @endif @if ($option['primary']) data-primary @endif wire:key="lang-{{ $option['code'] }}">
                        <label class="language-option__main">
                            <input type="checkbox" wire:model.live="enabledLocales" value="{{ $option['code'] }}" @disabled(! $canManage || $option['locked'])
                                   aria-label="{{ __('manager_settings.languages_ui.enable', ['language' => $option['native']]) }}"
                                   @if ($option['locked']) aria-describedby="language-primary-locked" @endif>
                            <img class="language-flag" src="{{ asset('icons/languages/'.$option['icon'].'.svg') }}" alt="" width="28" height="19">
                            <span dir="{{ $option['direction'] }}"><strong>{{ $option['short'] }}</strong><small>{{ $option['native'] }}</small></span>
                        </label>
                        @if ($option['primary'])
                            <x-ui.status tone="info" :label="__('manager_settings.languages.primary_badge')" :dot="false" />
                        @elseif ($option['enabled'] && $canManage)
                            <button class="text-button" type="button" wire:click="makePrimary('{{ $option['code'] }}')">{{ __('manager_settings.languages_ui.make_primary') }}</button>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="field-help" id="language-primary-locked">{{ __('manager_settings.languages_ui.primary_locked') }}</p>
            @error('enabledLocales')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
        </fieldset>

        <div class="field">
            <label for="primary-locale">{{ __('manager_settings.languages.primary') }}</label>
            <select id="primary-locale" wire:model.live="primaryLocale" @disabled(! $canManage)>
                @foreach ($options as $option)
                    <option value="{{ $option['code'] }}">{{ $option['short'] }} · {{ $option['native'] }}</option>
                @endforeach
            </select>
            <p class="field-help">{{ __('manager_settings.languages_ui.fallback', ['primary' => $primaryName]) }}</p>
            @error('primaryLocale')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
        </div>
    </div>
    @if ($canManage)
        <footer class="card__footer">
            <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveLanguages">{{ __('manager_settings.languages.save') }}</button>
        </footer>
    @endif
</form>
