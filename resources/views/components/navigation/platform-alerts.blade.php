{{--
    Super Admin bell. Opens a popover — it never navigates on its own; only
    "View all" leads to the full notifications page.
--}}
<details class="topbar-popover alert-popover" data-popover>
    <summary class="icon-button" aria-label="{{ __('superadmin_ui.notifications.open') }}" aria-haspopup="menu">
        <x-ui.icon name="bell" />
        @if($unreadCount > 0)
            <span class="notification-count" aria-label="{{ trans_choice('superadmin_ui.notifications.unread_count', $unreadCount, ['count' => $unreadCount]) }}">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </summary>
    <section class="topbar-popover__panel notification-panel" aria-label="{{ __('superadmin_ui.notifications.title') }}">
        <header class="notification-panel__header">
            <strong>{{ __('superadmin_ui.notifications.title') }}</strong>
            @if($unreadCount > 0)<span class="badge" data-tone="primary">{{ trans_choice('superadmin_ui.notifications.unread_count', $unreadCount, ['count' => $unreadCount]) }}</span>@endif
        </header>
        <div class="notification-list">
            @forelse($alerts as $alert)
                <article class="notification-item" @if($isUnread($alert)) data-unread="true" @endif>
                    <div class="notification-item__meta">
                        <span class="badge" data-severity="{{ $alert->severity }}">{{ __('superadmin_ui.status.'.$alert->severity) }}</span>
                        <time datetime="{{ $alert->created_at?->toAtomString() }}">{{ $alert->created_at?->diffForHumans() }}</time>
                    </div>
                    <strong>{{ $alert->title->get() }}</strong>
                    <p>{{ Str::limit($alert->body->get(), 140) }}</p>
                    @if($isUnread($alert))<span class="sr-only">{{ __('superadmin_ui.notifications.unread') }}</span>@endif
                </article>
            @empty
                <div class="empty-state empty-state--compact">
                    <span class="empty-state__icon"><x-ui.icon name="bell" /></span>
                    <strong>{{ __('superadmin_ui.notifications.empty_title') }}</strong>
                    <p>{{ __('superadmin_ui.notifications.empty_body') }}</p>
                </div>
            @endforelse
        </div>
        <footer><a class="notification-view-all" href="{{ route('superadmin.alerts.index') }}" wire:navigate>{{ __('superadmin_ui.notifications.view_all') }}<x-ui.icon name="arrow-right" size="16" /></a></footer>
    </section>
</details>
