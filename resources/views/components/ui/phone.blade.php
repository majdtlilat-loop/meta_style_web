@props([
    'number',
    'country',
    'label' => null,
    'required' => false,
    'help' => null,
    'id' => null,
])
{{--
    The ONE phone field: [flag +code ▾] [national number]. Binds two
    Livewire properties — the country (ISO code, Iraq by default) and the
    number as typed. The server turns them into one E.164 value with
    PhoneNumber::fromParts(); nothing here decides validity.

    The country list is searchable by name, ISO code or calling code, and
    keyboard operable (↑ ↓ Enter Esc). Flags are local files, one per COUNTRY
    — not the interface-language flags.
--}}
@php
    $locale = app()->getLocale();
    $options = \App\Kernel\Contact\PhoneCountries::options($locale);
    $codes = collect($options)->mapWithKeys(fn (array $option): array => [$option['iso'] => ['code' => $option['code'], 'name' => $option['name']]])->all();
    $id ??= 'phone-'.str_replace(['.', '_'], '-', $number);
    $label ??= __('phone_field.label');
    $describedBy = trim(($help ? $id.'-help ' : '').($errors->has($number) || $errors->has($country) ? $id.'-error' : ''));
@endphp
<div {{ $attributes->class(['field', 'phone-field', 'has-error' => $errors->has($number) || $errors->has($country)]) }}
     x-data="{
        open: false,
        query: '',
        active: null,
        country: $wire.entangle(@js($country)),
        codes: @js($codes),
        flagBase: @js(asset('icons/flags')),
        get code() { return this.codes[this.country]?.code ?? '' },
        get name() { return this.codes[this.country]?.name ?? '' },
        flag(iso) { return this.flagBase + '/' + String(iso || '').toLowerCase() + '.svg' },
        toggle() { this.open ? this.close() : this.show() },
        // Visibility is a synchronous `hidden` binding, not x-show (which waits for an animation frame),
        // so by $nextTick the list is on screen and focus() lands in the search box.
        show() { this.open = true; this.query = ''; this.active = null; this.$nextTick(() => { this.$refs.search.focus(); this.$refs.list.querySelector('[aria-selected=true]')?.scrollIntoView({ block: 'nearest' }) }) },
        close(focus = true) { this.open = false; if (focus) this.$refs.button.focus() },
        get needle() { return this.query.trim().toLowerCase().replace(/^\+/, '') },
        matches(el) { return this.needle === '' || el.dataset.search.includes(this.needle) },
        // From the query itself, never from what has been painted: Enter straight after typing picks a match.
        visible() { return Array.from(this.$refs.list.children).filter(el => this.matches(el)) },
        move(step) { const items = this.visible(); if (! items.length) return; const at = items.findIndex(el => el.dataset.iso === this.active); const next = items[(at + step + items.length) % items.length] ?? items[0]; this.active = next.dataset.iso; next.scrollIntoView({ block: 'nearest' }) },
        pickActive() { const items = this.visible(); const item = items.find(el => el.dataset.iso === this.active) ?? items[0]; if (item) this.choose(item.dataset.iso) },
        choose(iso) { this.country = iso; this.close(false); this.$nextTick(() => this.$refs.number.focus()) },
     }"
     x-on:keydown.escape="if (open) { $event.stopPropagation(); close() }"
     x-on:click.outside="open && close(false)">
    <label for="{{ $id }}">{{ $label }}@if($required)<span class="required" aria-hidden="true">*</span>@endif</label>
    <div class="phone-field__control" dir="ltr">
        <button class="phone-field__country" type="button" x-ref="button" x-on:click="toggle()" aria-haspopup="listbox" :aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $id }}-list"
                :aria-label="@js(__('phone_field.country')) + ': ' + name + ' +' + code">
            <img :src="flag(country)" alt="" width="24" height="18">
            <span class="phone-field__code" x-text="'+' + code"></span>
            <x-ui.icon name="chevron-down" size="14" />
        </button>
        <input id="{{ $id }}" x-ref="number" type="tel" inputmode="tel" autocomplete="tel-national" wire:model="{{ $number }}" maxlength="32"
               placeholder="{{ __('phone_field.placeholder') }}" @required($required) @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
    </div>
    <div class="phone-field__menu" hidden x-bind:hidden="! open">
        <div class="search-input">
            <x-ui.icon name="search" />
            <input type="search" x-ref="search" x-model="query" x-on:input="active = null" autocomplete="off"
                   placeholder="{{ __('phone_field.search') }}" aria-label="{{ __('phone_field.search') }}" aria-controls="{{ $id }}-list"
                   x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="pickActive()">
        </div>
        <ul class="phone-field__list" id="{{ $id }}-list" role="listbox" x-ref="list" aria-label="{{ __('phone_field.country') }}">
            @foreach($options as $option)
                <li role="option" data-iso="{{ $option['iso'] }}" data-search="{{ mb_strtolower($option['name'].' '.$option['iso'].' '.$option['code']) }}"
                    x-bind:hidden="! matches($el)" x-on:click="choose(@js($option['iso']))"
                    :aria-selected="country === @js($option['iso']) ? 'true' : 'false'"
                    :class="{ 'is-active': active === @js($option['iso']) }">
                    <img src="{{ $option['flag'] }}" alt="" width="24" height="18" loading="lazy">
                    <span class="phone-field__name">{{ $option['name'] }}</span>
                    <span class="phone-field__dial" dir="ltr">+{{ $option['code'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>
    @if($help)<p class="field-help" id="{{ $id }}-help">{{ $help }}</p>@endif
    @error($number)<p class="error" id="{{ $id }}-error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
    @error($country)<p class="error" id="{{ $id }}-error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
</div>
