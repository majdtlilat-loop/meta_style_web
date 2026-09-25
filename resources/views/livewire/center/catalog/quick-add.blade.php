{{-- One-line add into the list the library is showing; the drawer refines it. --}}
<form class="catalog-quick" wire:submit="add" novalidate aria-label="{{ __('manager_catalog.quick.label') }}">
    <span class="catalog-quick__icon" aria-hidden="true"><x-ui.icon name="plus" size="16" /></span>
    <div class="field catalog-quick__name">
        <label class="sr-only" for="quick-name">{{ __('manager_catalog.fields.name') }}</label>
        <input id="quick-name" type="text" wire:model="quickName" maxlength="190" lang="{{ $nameLang }}" dir="{{ $nameDir }}" placeholder="{{ __('manager_catalog.quick.name', ['category' => $target]) }}" autocomplete="off" required>
    </div>
    <div class="field catalog-quick__duration">
        <label class="sr-only" for="quick-duration">{{ __('manager_catalog.fields.duration') }}</label>
        <div class="input-affix">
            <input id="quick-duration" type="number" dir="ltr" inputmode="numeric" min="1" max="1440" wire:model="quickDuration" required>
            <span class="input-affix__suffix">{{ __('manager_catalog.editor.minutes') }}</span>
        </div>
    </div>
    <div class="field catalog-quick__price">
        <label class="sr-only" for="quick-price">{{ __('manager_catalog.fields.price') }}</label>
        <div class="input-affix">
            <input id="quick-price" type="text" dir="ltr" inputmode="decimal" maxlength="32" wire:model="quickPrice" placeholder="{{ __('manager_catalog.fields.price') }}" autocomplete="off" required>
            <span class="input-affix__suffix" dir="ltr">{{ $currency }}</span>
        </div>
    </div>
    <button class="button button--secondary" type="submit" wire:loading.attr="disabled" wire:target="add">{{ __('manager_catalog.quick.submit') }}</button>

    @if($errors->any() || $notice !== '')
        <div class="catalog-quick__feedback">
            @foreach(['quickName', 'quickDuration', 'quickPrice'] as $field)
                @error($field)<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
            @endforeach
            <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />
        </div>
    @endif
</form>
