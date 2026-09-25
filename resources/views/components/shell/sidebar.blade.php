@props([
    'id',
    'label',
    'context',
    'groups' => [],
    'home' => null,
])
{{--
    Shared sidebar for Super Admin and the Manager.

    `groups` is a list of ['label' => ?string, 'items' => [...]]; each item is
    ['href', 'icon', 'label', 'active', 'badge' => ?string, 'count' => ?int].
    Items arrive already filtered by permission — hiding a link is orientation
    only; every route and action authorises again on the server.

    Optional (Manager): 'locked' => true with 'feature', 'lock_label',
    'tooltip' and 'aria_label'. A locked item is a plain link to its fallback
    (the plan page) WITHOUT wire:navigate; resources/js/manager/shell.js opens
    the upgrade prompt instead ([data-upgrade-feature]). Its lock stays visible
    in the collapsed rail and the tooltip carries the label.
--}}
<aside class="app-sidebar" id="{{ $id }}" data-app-sidebar>
    <div class="app-sidebar__header">
        <x-brand.logo :context="$context" :href="$home" />
        <button type="button" class="icon-button sidebar-collapse" data-sidebar-collapse
                data-label-collapse="{{ __('ui.shell.collapse_sidebar') }}" data-label-expand="{{ __('ui.shell.expand_sidebar') }}"
                aria-label="{{ __('ui.shell.collapse_sidebar') }}" title="{{ __('ui.shell.collapse_sidebar') }}" aria-pressed="false">
            <span class="sidebar-collapse__collapse"><x-ui.icon name="collapse" /></span>
            <span class="sidebar-collapse__expand"><x-ui.icon name="expand" /></span>
        </button>
        <button type="button" class="icon-button sidebar-close" data-nav-close aria-label="{{ __('ui.shell.close_navigation') }}"><x-ui.icon name="close" /></button>
    </div>
    <div class="app-sidebar__body">
        <nav class="app-nav" aria-label="{{ $label }}">
            @foreach($groups as $group)
                @if(($group['items'] ?? []) !== [])
                    <div class="app-nav__group" role="group" @if(! empty($group['label'])) aria-label="{{ $group['label'] }}" @endif>
                        @if(! empty($group['label']))<span class="app-nav__label" aria-hidden="true">{{ $group['label'] }}</span>@endif
                        @foreach($group['items'] as $item)
                            @if(! empty($item['locked']))
                            <a href="{{ $item['href'] }}" data-locked data-upgrade-feature="{{ $item['feature'] }}" aria-haspopup="dialog" aria-label="{{ $item['aria_label'] ?? $item['label'] }}" data-tooltip="{{ $item['tooltip'] ?? $item['label'] }}" @if($item['active']) aria-current="page" @endif>
                                <x-ui.icon :name="$item['icon']" />
                                <span class="app-nav__text">
                                    <span class="app-nav__stack">
                                        <span class="truncate">{{ $item['label'] }}</span>
                                        @if(! empty($item['lock_label']))<small class="app-nav__lock-label truncate">{{ $item['lock_label'] }}</small>@endif
                                    </span>
                                    <x-ui.icon name="lock" size="14" class="app-nav__lock" />
                                </span>
                                <span class="app-nav__lock-dot" aria-hidden="true"><x-ui.icon name="lock" size="10" /></span>
                            </a>
                            @continue
                            @endif
                            <a href="{{ $item['href'] }}" aria-label="{{ $item['label'] }}" data-tooltip="{{ $item['label'] }}" @if($item['active']) aria-current="page" @endif wire:navigate>
                                <x-ui.icon :name="$item['icon']" />
                                <span class="app-nav__text">
                                    <span class="truncate">{{ $item['label'] }}</span>
                                    @if(! empty($item['badge']))<span class="badge badge--pro">{{ $item['badge'] }}</span>@endif
                                </span>
                                @if(($item['count'] ?? 0) > 0)<span class="app-nav__count">{{ $item['count'] > 99 ? '99+' : $item['count'] }}</span>@endif
                            </a>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </nav>
    </div>
</aside>
