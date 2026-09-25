{{--
    RAYAN: on/off, an approved model, the tone. What a center may NOT set (key,
    provider, URL, limits) has no field here. Values are prepared by
    App\Livewire\Center\Integrations\AssistantSettings.
--}}
<form class="card card--flush wa-assistant" wire:submit="save" aria-labelledby="wa-assistant-title">
    <header class="card__header">
        <div><h2 id="wa-assistant-title">{{ __('manager_whatsapp.assistant.title') }}</h2></div>
        @if($owned)
            <x-ui.status :tone="$isOn && $available ? 'success' : 'neutral'" :label="__($isOn && $available ? 'manager_whatsapp.status.on' : 'manager_whatsapp.status.off')" />
        @endif
    </header>
    <div class="card__body stack stack--sm">
        @if(! $owned)
            @if($offer)
                <x-manager.feature-locked :offer="$offer" compact />
            @endif
        @else
            <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

            @unless($available)
                <div class="notice" data-tone="warning" role="status"><x-ui.icon name="alert-triangle" /><p>{{ __('manager_whatsapp.assistant.unavailable') }}</p></div>
            @endunless

            <label class="choice choice--switch">
                <input type="checkbox" class="switch" role="switch" wire:model="enabled" @disabled(! $canManage || ! $available)>
                <span>
                    {{ __('manager_whatsapp.assistant.enabled') }}
                    <small class="field-help">{{ __('manager_whatsapp.assistant.off_note') }}</small>
                </span>
            </label>

            @if(count($models) > 1)
                <x-ui.field :label="__('manager_whatsapp.assistant.model')" for="wa-model" name="model">
                    <select id="wa-model" wire:model="model" dir="ltr" @disabled(! $canManage)>
                        <option value="">{{ $defaultModel ? __('manager_whatsapp.assistant.default_model', ['model' => $defaultModel]) : __('manager_whatsapp.assistant.default_model_plain') }}</option>
                        @foreach($models as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            @elseif($effectiveModel)
                <dl class="summary-list wa-summary">
                    <div><dt>{{ __('manager_whatsapp.assistant.model') }}</dt><dd><span class="mono" dir="ltr">{{ $effectiveModel }}</span></dd></div>
                </dl>
            @endif

            <div class="field" x-data="{ count: {{ $toneLength }} }">
                <div class="field__row">
                    <label for="wa-tone">
                        {{ __('manager_whatsapp.assistant.tone') }}
                        <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_whatsapp.assistant.tone_tip') }}" aria-label="{{ __('manager_whatsapp.assistant.tone_tip') }}"><x-ui.icon name="info" size="16" /></span>
                    </label>
                    <span class="char-count" dir="ltr" x-bind:data-over="count > {{ $maxTone }} ? 'true' : 'false'"><span x-text="count">{{ $toneLength }}</span>/{{ $maxTone }}</span>
                </div>
                <textarea id="wa-tone" wire:model="tone" rows="3" maxlength="{{ $maxTone }}" placeholder="{{ __('manager_whatsapp.assistant.tone_placeholder') }}" x-on:input="count = $event.target.value.length" @disabled(! $canManage)></textarea>
                @error('tone')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                @error('model')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
            </div>

            @unless($canManage)
                <p class="field-help"><x-ui.icon name="lock" size="14" /> {{ __('manager_whatsapp.assistant.read_only') }}</p>
            @endunless
        @endif
    </div>
    @if($canManage)
        <footer class="card__footer">
            <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save"><x-ui.icon name="save" size="16" />{{ __('manager_whatsapp.assistant.save') }}</button>
        </footer>
    @endif
</form>
