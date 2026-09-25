{{--
    Manager → Settings. The sections are child components, each authorising
    itself; the links lead to pages with their own home. Everything here is
    computed by App\Livewire\Center\Settings — no logic in this template.
--}}
<div class="stack">
    <x-ui.page-header :title="__('manager_settings.title')" />

    <x-ui.flash />

    <div class="settings-layout">
        <nav class="settings-nav" aria-label="{{ __('manager_settings.nav.label') }}">
            @foreach ($tabs as $item)
                <button type="button" wire:click="showTab('{{ $item['key'] }}')" @if ($current === $item['key']) aria-current="page" @endif>
                    <x-ui.icon :name="$item['icon']" size="16" />{{ $item['label'] }}
                    @if ($item['locked'] ?? false)<span class="badge" data-tone="neutral">{{ __('manager_settings.links.locked') }}</span>@endif
                </button>
            @endforeach
            @if ($links !== [])
                <span class="settings-nav__heading">{{ __('manager_settings.nav.more') }}</span>
                @foreach ($links as $link)
                    <a href="{{ $link['href'] }}" wire:navigate>
                        <x-ui.icon :name="$link['icon']" size="16" />{{ $link['label'] }}
                        @if ($link['badge'])<span class="badge" data-tone="neutral">{{ $link['badge'] }}</span>@endif
                        <x-ui.icon name="chevron-right" size="14" class="settings-nav__chevron ui-icon--directional" />
                    </a>
                @endforeach
                {{-- [area:integrations] Settings → WhatsApp; its permission, lock and alert are decided by the component. --}}
                <livewire:center.integrations.settings-link wire:key="settings-whatsapp-link" />
            @endif
        </nav>

        <div class="settings-panel" wire:loading.class="is-refreshing" wire:target="showTab">
            @switch ($current)
                @case('languages')
                    <livewire:center.settings.languages wire:key="settings-languages" />
                    @break
                @case('booking')
                    <livewire:center.settings.booking-rules wire:key="settings-booking" />
                    @break
                @case('policies')
                    <livewire:center.settings.policies wire:key="settings-policies" />
                    @break
                @case('notifications')
                    <livewire:center.settings.notifications wire:key="settings-notifications" />
                    @break
                @default
                    <livewire:center.settings.general wire:key="settings-general" />
            @endswitch
        </div>
    </div>
</div>
