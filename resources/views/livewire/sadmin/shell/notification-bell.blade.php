{{--
    Super Admin bell. Opening it never navigates; "View all" and a
    notification's own action are the only links. `wire:ignore.self` keeps the
    popover open while the poll refreshes what is inside it.
--}}
<div class="bell" wire:poll.20s.visible="poll">
    <details class="topbar-popover alert-popover" data-popover wire:ignore.self>
        <summary class="icon-button" aria-label="{{ $unreadCount > 0 ? trans_choice('sadmin_shell.bell.open_unread', $unreadCount, ['count' => $unreadCount]) : __('sadmin_shell.bell.open') }}" aria-haspopup="menu">
            <x-ui.icon name="bell" />
            @if($unreadCount > 0)
                <span class="notification-count" aria-hidden="true">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
            @endif
        </summary>
        <section class="topbar-popover__panel notification-panel" aria-label="{{ __('sadmin_shell.bell.title') }}">
            <header class="notification-panel__header">
                <strong>{{ __('sadmin_shell.bell.title') }}</strong>
                <div class="cluster cluster--tight">
                    <button type="button" class="icon-button icon-button--sm" data-sound-toggle wire:ignore
                            data-label-mute="{{ __('sadmin_shell.bell.mute') }}" data-label-unmute="{{ __('sadmin_shell.bell.unmute') }}"
                            aria-pressed="false" aria-label="{{ __('sadmin_shell.bell.mute') }}" title="{{ __('sadmin_shell.bell.mute') }}">
                        <span class="sound-on"><x-ui.icon name="volume" size="16" /></span>
                        <span class="sound-off"><x-ui.icon name="volume-off" size="16" /></span>
                    </button>
                    @if($unreadCount > 0)
                        <button type="button" class="text-button" wire:click="markAllRead">{{ __('sadmin_shell.bell.mark_all') }}</button>
                    @endif
                </div>
            </header>
            <div class="notification-list" role="list">
                @forelse($alerts as $alert)
                    @php
                        $unread = ! in_array((int) $alert->id, $readIds, true);
                    @endphp
                    <article class="notification-item" role="listitem" @if($unread) data-unread="true" @endif wire:key="bell-{{ $alert->id }}">
                        <div class="notification-item__meta">
                            <span class="badge" data-severity="{{ $alert->severity }}">{{ \App\View\Label::for('severity', (string) $alert->severity) }}</span>
                            <time datetime="{{ $alert->created_at?->toAtomString() }}">{{ $alert->created_at?->diffForHumans() }}</time>
                        </div>
                        <strong>{{ $alert->title->get() }}</strong>
                        <p>{{ \Illuminate\Support\Str::limit($alert->body->get(), 140) }}</p>
                        <div class="notification-item__actions">
                            @if($alert->action_url)
                                <button type="button" class="text-button" wire:click="open({{ $alert->id }})">{{ __('sadmin_shell.bell.open_item') }}</button>
                            @endif
                            @if($unread)
                                <button type="button" class="text-button text-button--muted" wire:click="markRead({{ $alert->id }})">{{ __('sadmin_shell.bell.mark_read') }}</button>
                                <span class="sr-only">{{ __('sadmin_shell.bell.unread') }}</span>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="empty-state empty-state--compact">
                        <span class="empty-state__icon"><x-ui.icon name="bell" /></span>
                        <strong>{{ __('sadmin_shell.bell.empty') }}</strong>
                    </div>
                @endforelse
            </div>
            <footer><a class="notification-view-all" href="{{ route('superadmin.alerts.index') }}" wire:navigate>{{ __('sadmin_shell.bell.view_all') }}<x-ui.icon name="arrow-right" size="16" /></a></footer>
        </section>
    </details>
</div>
