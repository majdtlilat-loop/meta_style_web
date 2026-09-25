<x-ui.card :title="__('manager_site.panels.footer')">
    <x-slot:actions>
        <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="content.footer.enabled"><span>{{ __('manager_site.fields.show_section') }}</span></label>
    </x-slot:actions>
    <div class="stack">
        <div class="sb-switches">
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="content.footer.show_logo"><span>{{ __('manager_site.footer.show_logo') }}</span></label>
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="content.footer.show_hours"><span>{{ __('manager_site.footer.show_hours') }}</span></label>
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="content.footer.show_booking_cta"><span>{{ __('manager_site.footer.show_booking_cta') }}</span></label>
        </div>
        <x-ui.lang-tabs id="site-footer-text" :locales="$locales" :primary="$primary" :values="[
            'content.footer.description' => $content['footer']['description'], 'content.footer.address' => $content['footer']['address'], 'content.footer.copyright' => $content['footer']['copyright'],
        ]" :fields="[
            ['name' => 'content.footer.description', 'label' => __('manager_site.footer.description'), 'type' => 'textarea', 'rows' => 2, 'max' => $choices['limits']['footer_description'], 'counter' => true],
            ['name' => 'content.footer.address', 'label' => __('manager_site.footer.address'), 'max' => $choices['limits']['address']],
            ['name' => 'content.footer.copyright', 'label' => __('manager_site.footer.copyright'), 'max' => $choices['limits']['copyright']],
        ]" />
        <div class="cms-grid-3 sb-contact-grid">
            <x-ui.phone number="phones.phone.number" country="phones.phone.country" id="site-footer-phone" :label="__('manager_site.footer.contact_phone')" />
            <x-ui.phone number="phones.whatsapp.number" country="phones.whatsapp.country" id="site-footer-whatsapp" :label="__('manager_site.footer.contact_whatsapp')" />
            <x-ui.field :label="__('manager_site.footer.contact_email')" for="site-footer-email" name="content.footer.contact_email">
                <input id="site-footer-email" type="email" dir="ltr" wire:model.blur="content.footer.contact_email" maxlength="120" autocomplete="off">
            </x-ui.field>
        </div>
        <p class="field-help">{{ __('manager_site.footer.contact_note') }}</p>
    </div>
</x-ui.card>

<x-ui.card :title="__('manager_site.footer.social')" :description="__('manager_site.footer.social_help')">
    <x-slot:actions>
        @if($canManage)
            <button class="button button--secondary button--sm" type="button" wire:click="addItem('footer.social')" @disabled(count($content['footer']['social']) >= $choices['max']['social'])><x-ui.icon name="plus" size="16" />{{ __('manager_site.actions.add_social') }}</button>
        @endif
    </x-slot:actions>
    @if($content['footer']['social'] === [])
        <x-ui.empty-state compact icon="globe" :title="__('manager_site.empty.social')" />
    @else
        <ul class="cms-item-list sb-list" @if($canManage) x-data x-sortable="sortItems" data-sortable-group="footer-social" @endif>
            @foreach($content['footer']['social'] as $index => $link)
                <li class="cms-row sb-social-row" data-sortable-item="footer.social|{{ $link['id'] }}" wire:key="social-{{ $link['id'] }}">
                    <div class="sb-social-row__network">
                        @if($canManage)
                            <button type="button" class="icon-button icon-button--sm sb-grip" data-sortable-handle aria-label="{{ __('manager_site.actions.drag', ['name' => $choices['networks'][$link['network']] ?? '']) }}"><x-ui.icon name="grip" /></button>
                        @endif
                        <select wire:model="content.footer.social.{{ $index }}.network" aria-label="{{ __('manager_site.footer.network') }}">
                            @foreach($choices['networks'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                        </select>
                    </div>
                    <div class="field">
                        <input dir="ltr" type="url" inputmode="url" wire:model.blur="content.footer.social.{{ $index }}.url" placeholder="https://" maxlength="300" aria-label="{{ __('manager_site.fields.url') }}">
                        @error('content.footer.social.'.$index.'.url')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                    </div>
                    <div class="cms-item__tools">
                        @if($canManage)
                            <button class="icon-button icon-button--sm" type="button" wire:click="moveItem('footer.social', '{{ $link['id'] }}', -1)" @disabled($loop->first) aria-label="{{ __('manager_site.actions.move_up') }}"><x-ui.icon name="arrow-up" /></button>
                            <button class="icon-button icon-button--sm" type="button" wire:click="moveItem('footer.social', '{{ $link['id'] }}', 1)" @disabled($loop->last) aria-label="{{ __('manager_site.actions.move_down') }}"><x-ui.icon name="arrow-down" /></button>
                        @endif
                        <label class="switch-label"><input class="switch" type="checkbox" role="switch" wire:model.live="content.footer.social.{{ $index }}.enabled" aria-label="{{ __('manager_site.fields.enabled') }}"></label>
                        @if($canManage)
                            <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeItem('footer.social', '{{ $link['id'] }}')" wire:confirm="{{ __('manager_site.confirm.remove_link') }}" data-confirm-title="{{ __('manager_site.confirm.remove_title') }}" data-confirm-tone="danger" aria-label="{{ __('manager_site.actions.remove') }}"><x-ui.icon name="trash" /></button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>

@include('livewire.center.appearance.site.partials.link-list', ['list' => 'footer.navigation', 'items' => $content['footer']['navigation'], 'max' => $choices['max']['footer'], 'title' => __('manager_site.footer.navigation'), 'description' => null])
@include('livewire.center.appearance.site.partials.link-list', ['list' => 'footer.legal', 'items' => $content['footer']['legal'], 'max' => $choices['max']['legal'], 'title' => __('manager_site.footer.legal'), 'description' => null])
