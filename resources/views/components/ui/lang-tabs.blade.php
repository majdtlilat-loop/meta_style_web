@props([
    'id',
    'fields',
    'values' => [],
    'locales' => null,
    'primary' => null,
    'live' => false,
])
{{--
    The ONE multilingual editor: a tab per language, the primary one marked
    and required, a dot on any language still missing text. Fields bind to
    `<name>.<locale>` so each language is a separate value.

    `locales` defaults to every interface language (platform content); a
    center passes its ENABLED content languages and its primary one instead.
    Hidden languages keep their stored text — this only chooses what shows.

    fields: [['name' => 'name', 'label' => '…', 'type' => 'input'|'textarea', 'rows' => 3, 'max' => 190, 'required' => true]]
    live: send each change as it is typed (debounced), e.g. for a live preview.
--}}
@php
    $registry = app(\App\Kernel\Localization\LanguageRegistry::class);
    $locales = array_values($locales ?? $registry->supported());
    $primary = $primary ?? ($locales[0] ?? 'en');
    // Primary first, the rest in their configured order.
    usort($locales, fn ($a, $b) => ($b === $primary) <=> ($a === $primary));
    $missing = [];
    foreach ($locales as $locale) {
        foreach ($fields as $field) {
            if (($field['required'] ?? false) || $locale !== $primary) {
                $text = trim((string) ($values[$field['name']][$locale] ?? ''));
                if ($text === '' && ($field['required'] ?? false) && $locale === $primary) {
                    $missing[$locale] = true;
                }
                if ($text === '' && $locale !== $primary && trim((string) ($values[$field['name']][$primary] ?? '')) !== '') {
                    $missing[$locale] = true;
                }
            }
        }
    }
    $hasErrors = fn (string $locale) => collect($fields)->contains(fn ($field) => $errors->has($field['name'].'.'.$locale));
    $initial = collect($locales)->first(fn ($locale) => $hasErrors($locale)) ?? $primary;
@endphp
<div {{ $attributes->class(['lang-tabs']) }} x-data="{ active: @js($initial) }">
    <div class="lang-tabs__bar">
        <div class="lang-tabs__list" role="tablist" aria-label="{{ __('ui.languages.content_languages') }}"
             x-on:keydown.right.prevent="$focus.wrap().next()" x-on:keydown.left.prevent="$focus.wrap().previous()">
            @foreach($locales as $locale)
                <button class="lang-tabs__tab" type="button" role="tab" id="{{ $id }}-tab-{{ $locale }}"
                        aria-controls="{{ $id }}-panel-{{ $locale }}"
                        :aria-selected="active === @js($locale) ? 'true' : 'false'"
                        :tabindex="active === @js($locale) ? 0 : -1"
                        x-on:click="active = @js($locale)">
                    {{ $registry->shortLabel($locale) }}
                    @if($locale === $primary)<span class="lang-tabs__primary" aria-label="{{ __('ui.languages.primary_hint') }}" title="{{ __('ui.languages.primary_hint') }}">★</span>@endif
                    @if($hasErrors($locale))
                        <span class="lang-tabs__error" aria-hidden="true"></span>
                    @elseif(isset($missing[$locale]))
                        <span class="lang-tabs__missing" title="{{ __('ui.states.missing_translation') }}"></span><span class="sr-only">{{ __('ui.states.missing_translation') }}</span>
                    @endif
                </button>
            @endforeach
        </div>
        <span class="lang-tabs__note" x-text="active === @js($primary) ? @js(__('ui.languages.primary_hint')) : @js(__('ui.languages.secondary_hint'))"></span>
    </div>

    @foreach($locales as $locale)
        <div class="lang-tabs__panel stack stack--sm" role="tabpanel" id="{{ $id }}-panel-{{ $locale }}" aria-labelledby="{{ $id }}-tab-{{ $locale }}"
             x-show="active === @js($locale)" @if($locale !== $initial) x-cloak @endif
             dir="{{ $registry->direction($locale) }}" lang="{{ $locale }}">
            @foreach($fields as $field)
                @php
                    $inputId = $id.'-'.$field['name'].'-'.$locale;
                    $required = ($field['required'] ?? false) && $locale === $primary;
                @endphp
                @php
                    $counter = ! empty($field['counter']) && ! empty($field['max']);
                    $length = mb_strlen((string) ($values[$field['name']][$locale] ?? ''));
                @endphp
                <div class="field" @if($counter) x-data="{ count: {{ $length }} }" @endif>
                    <div class="field__row">
                        <label for="{{ $inputId }}">{{ $field['label'] }} <span class="muted">· {{ $registry->nativeName($locale) }}</span>@if($required)<span class="required" aria-hidden="true">*</span>@endif</label>
                        @if($counter)<span class="char-count" dir="ltr" :data-over="count > {{ (int) ($field['recommended'] ?? $field['max']) }}" x-text="count + ' / {{ (int) ($field['recommended'] ?? $field['max']) }}'" aria-hidden="true">{{ $length }} / {{ (int) ($field['recommended'] ?? $field['max']) }}</span>@endif
                    </div>
                    @if(($field['type'] ?? 'input') === 'textarea')
                        <textarea id="{{ $inputId }}" rows="{{ $field['rows'] ?? 3 }}" wire:model{{ $live ? '.live.debounce.600ms' : '' }}="{{ $field['name'] }}.{{ $locale }}" @if(! empty($field['max'])) maxlength="{{ $field['max'] }}" @endif @if($counter) x-on:input="count = $event.target.value.length" @endif @required($required)></textarea>
                    @else
                        <input id="{{ $inputId }}" type="text" wire:model{{ $live ? '.live.debounce.600ms' : '' }}="{{ $field['name'] }}.{{ $locale }}" @if(! empty($field['max'])) maxlength="{{ $field['max'] }}" @endif @if($counter) x-on:input="count = $event.target.value.length" @endif @required($required)>
                    @endif
                    @error($field['name'].'.'.$locale)<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                </div>
            @endforeach
        </div>
    @endforeach
</div>
