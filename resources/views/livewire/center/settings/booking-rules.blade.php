<form class="card card--flush" wire:submit="save" aria-labelledby="booking-rules-title" novalidate>
    <header class="card__header">
        <div>
            <h2 id="booking-rules-title">{{ __('manager_settings.booking.title') }}</h2>
        </div>
    </header>
    <div class="card__body stack">
        @if ($offer)
            <x-manager.feature-locked :offer="$offer" compact />
        @endif
        <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="$set('notice', '')" />

        <div class="form-grid">
            @foreach ($fields as $field)
                <x-ui.field :label="$field['label']" for="rule-{{ $field['key'] }}" name="rules.{{ $field['key'] }}" :help="$field['help']" wire:key="rule-{{ $field['key'] }}">
                    <div class="input-affix">
                        <input id="rule-{{ $field['key'] }}" type="number" inputmode="numeric" min="{{ $field['min'] }}" max="{{ $field['max'] }}" step="1"
                               wire:model="rules.{{ $field['key'] }}" dir="ltr" @disabled(! $canManage) required>
                        <span class="input-affix__suffix">{{ $field['unit'] }}</span>
                    </div>
                </x-ui.field>
            @endforeach
        </div>
    </div>
    @if ($canManage)
        <footer class="card__footer">
            <p class="field-help grow">{{ __('manager_settings.booking.note') }}</p>
            <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save"><x-ui.icon name="save" size="16" />{{ __('manager_settings.booking.save') }}</button>
        </footer>
    @endif
</form>
