{{--
    Manager bell. Opening it never navigates; "View all" and a notification's
    own link are the only ways out. `wire:ignore.self` keeps the popover open
    while the poll refreshes what is inside it.
--}}
<div class="bell manager-bell" wire:poll.20s.visible="poll">
    <details class="topbar-popover" data-popover wire:ignore.self>
        <summary class="icon-button" aria-label="{{ $unread > 0 ? trans_choice('manager_shell.bell.open_unread', $unread, ['count' => $unread]) : __('manager_shell.bell.open') }}" aria-haspopup="menu">
            <x-ui.icon name="bell" />
            @if($unread > 0)
                <span class="notification-count" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>
            @endif
        </summary>
        <section class="topbar-popover__panel notification-panel" aria-label="{{ __('manager_shell.bell.title') }}">
            <header class="notification-panel__header">
                <strong>{{ __('manager_shell.bell.title') }}</strong>
                <div class="cluster cluster--tight">
                    <button type="button" class="icon-button icon-button--sm" data-sound-toggle wire:ignore
                            data-label-mute="{{ __('manager_shell.bell.mute') }}" data-label-unmute="{{ __('manager_shell.bell.unmute') }}"
                            aria-pressed="false" aria-label="{{ __('manager_shell.bell.mute') }}" title="{{ __('manager_shell.bell.mute') }}">
                        <span class="sound-on"><x-ui.icon name="volume" size="16" /></span>
                        <span class="sound-off"><x-ui.icon name="volume-off" size="16" /></span>
                    </button>
                    @if($unread > 0)
                        <button type="button" class="text-button" wire:click="markAllRead" wire:loading.attr="disabled" wire:target="markAllRead">{{ __('manager_shell.bell.mark_all') }}</button>
                    @endif
                </div>
            </header>
            <div class="notification-list" role="list">
                @forelse($items as $item)
                    <article class="notification-item" role="listitem" @if($item['unread']) data-unread="true" @endif wire:key="bell-{{ $item['id'] }}">
                        <div class="notification-item__meta">
                            <span @class(['manager-bell__kind', 'is-important' => $item['important']])>{{ $item['kind'] }}</span>
                            <time datetime="{{ $item['datetime'] }}" title="{{ $item['absolute'] }}">{{ $item['relative'] }}</time>
                        </div>
                        <p>{{ $item['message'] }}</p>
                        @if($item['href'] || $item['unread'])
                            <div class="notification-item__actions">
                                @if($item['href'])
                                    <a class="text-button" href="{{ $item['href'] }}" wire:navigate>{{ __('manager_shell.bell.open_item') }}</a>
                                @endif
                                @if($item['unread'])
                                    <button type="button" class="text-button text-button--muted" wire:click="markRead('{{ $item['id'] }}')" wire:loading.attr="disabled" wire:target="markRead('{{ $item['id'] }}')">{{ __('manager_shell.bell.mark_read') }}</button>
                                    <span class="sr-only">{{ __('manager_shell.bell.unread') }}</span>
                                @endif
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="empty-state empty-state--compact">
                        <span class="empty-state__icon"><x-ui.icon name="bell" /></span>
                        <strong>{{ __('manager_shell.bell.empty') }}</strong>
                    </div>
                @endforelse
            </div>
            @if($viewAll)
                <footer><a class="notification-view-all" href="{{ $viewAll }}" wire:navigate>{{ __('manager_shell.bell.view_all') }}<x-ui.icon name="arrow-right" size="16" /></a></footer>
            @endif
        </section>
    </details>
</div>
