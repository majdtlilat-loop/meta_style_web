{{--
    The Settings section list. Tabs of the main Settings page switch in place
    (`$internal`); Branding, AI and Invoice templates are their own pages with
    their own permission, so each person sees only what they may change.
--}}
@props(['current', 'internal' => false])
@php
    $user = auth('platform')->user();
    $can = static fn (string $permission): bool => (bool) $user?->hasPermission($permission);
    $settings = $can('platform.settings.manage');
    $sections = array_filter([
        'general' => $settings ? ['icon' => 'settings', 'tab' => true] : null,
        'commercial' => $settings ? ['icon' => 'plans', 'tab' => true] : null,
        'security' => $settings ? ['icon' => 'shield', 'tab' => true] : null,
        'branding' => $can('platform.branding.manage') ? ['icon' => 'palette', 'route' => 'superadmin.settings.branding'] : null,
        'ai' => $can('platform.ai.manage') ? ['icon' => 'sparkles', 'route' => 'superadmin.settings.ai'] : null,
        'invoices' => $can('platform.billing.manage') ? ['icon' => 'receipt', 'route' => 'superadmin.settings.invoices'] : null,
        'email' => $settings ? ['icon' => 'mail', 'tab' => true] : null,
        'notifications' => $settings ? ['icon' => 'bell', 'tab' => true] : null,
        'localization' => $settings ? ['icon' => 'languages', 'tab' => true] : null,
    ]);
@endphp
<nav class="settings-nav" aria-label="{{ __('platform_settings.title') }}">
    @foreach($sections as $key => $section)
        @if(($section['tab'] ?? false) && $internal)
            <button type="button" wire:click="showTab('{{ $key }}')" @if($current === $key) aria-current="page" @endif>
                <x-ui.icon :name="$section['icon']" size="18" /><span>{{ __('platform_settings.tabs.'.$key) }}</span>
            </button>
        @else
            <a href="{{ isset($section['route']) ? route($section['route']) : route('superadmin.settings.index', $key === 'general' ? [] : ['tab' => $key]) }}" wire:navigate @if($current === $key) aria-current="page" @endif>
                <x-ui.icon :name="$section['icon']" size="18" /><span>{{ __('platform_settings.tabs.'.$key) }}</span>
            </a>
        @endif
    @endforeach
</nav>
