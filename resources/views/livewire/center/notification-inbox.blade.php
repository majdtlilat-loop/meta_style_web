{{--
    The staff member's own notifications.

    docs/23-NOTIFICATIONS.md §§16–17. Their own inbox and nobody else's: there
    is no recipient parameter on this page to change. Every message is built
    from the notification's parameters at read time, in the reader's language,
    and escaped here. Times are relative in the reader's language, with the
    exact time (their branch's timezone) on hover.
--}}
<div class="stack">
    <x-ui.page-header :title="__('notifications_inbox.title')">
        <x-slot:meta>
            <span class="result-count">{{ __('notifications_inbox.unread') }}: <strong>{{ $unread }}</strong></span>
        </x-slot:meta>
        @if($unread > 0)
            <x-slot:actions>
                <button class="button button--secondary" type="button" wire:click="markAllRead" wire:loading.attr="data-loading" wire:target="markAllRead"><x-ui.icon name="check" size="16" />{{ __('notifications_inbox.mark_all_read') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if($error !== '')
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>
    @endif

    <div class="record-grid">
        <section class="card card--flush inbox-list" aria-live="polite">
            <header class="card__header inbox-list__header">
                <div class="segmented" role="group" aria-label="{{ __('notifications_inbox.filter_label') }}">
                    <button type="button" wire:click="setFilter('all')" aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}">{{ __('notifications_inbox.filter_all') }}</button>
                    <button type="button" wire:click="setFilter('unread')" aria-pressed="{{ $filter === 'unread' ? 'true' : 'false' }}">{{ __('notifications_inbox.filter_unread') }}@if($unread > 0)<span class="segmented__count">{{ $unread }}</span>@endif</button>
                </div>
            </header>

            <div wire:loading.class="is-refreshing" wire:target="setFilter,loadMore,markAllRead">
                @if($notifications === [])
                    <x-ui.empty-state icon="notifications" :title="$filter === 'unread' ? __('notifications_inbox.empty_unread') : __('notifications_inbox.empty')" />
                @else
                    <ul class="alert-feed">
                        @foreach($notifications as $notification)
                            <li class="alert-feed__item" @if($notification['unread']) data-unread="true" @endif wire:key="notification-{{ $notification['id'] }}">
                                <span class="row-list__icon" data-tone="{{ $notification['important'] ? 'warning' : 'info' }}" aria-hidden="true"><x-ui.icon :name="$notification['important'] ? 'alert-triangle' : 'bell'" /></span>
                                <div class="alert-feed__body">
                                    <span class="inbox-list__kind">{{ $notification['kind'] }}</span>
                                    <strong class="alert-feed__message">{{ $notification['message'] }}</strong>
                                    <div class="alert-feed__meta">
                                        <time datetime="{{ $notification['datetime'] }}" title="{{ $notification['absolute'] }}">{{ $notification['relative'] }}</time>
                                        @if($notification['href'])
                                            <a class="text-button" href="{{ $notification['href'] }}" wire:navigate>{{ __('manager_shell.bell.open_item') }}</a>
                                        @endif
                                        @if($notification['unread'])
                                            <button class="text-button" type="button" wire:click="markRead('{{ $notification['id'] }}')" wire:loading.attr="disabled" wire:target="markRead('{{ $notification['id'] }}')">{{ __('notifications_inbox.mark_read') }}</button>
                                            <span class="sr-only">{{ __('notifications_inbox.unread') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if($canLoadMore)
                        <footer class="card__footer inbox-list__more">
                            <button class="button button--secondary button--sm" type="button" wire:click="loadMore" wire:loading.attr="data-loading" wire:target="loadMore">{{ __('notifications_inbox.load_more') }}</button>
                        </footer>
                    @endif
                @endif
            </div>
        </section>

        @if($preferences !== [])
        <x-ui.card :title="__('notifications_inbox.preferences')">
            <div class="stack stack--sm">
                @foreach($preferences as $preference)
                    <label class="choice choice--switch" wire:key="pref-{{ $preference['key'] }}">
                        <input class="switch" type="checkbox" role="switch" @checked($preference['on']) wire:click="togglePreference('{{ $preference['key'] }}')">
                        <span>{{ $preference['label'] }}</span>
                    </label>
                @endforeach
            </div>
            <x-slot:footer><span class="field-help">{{ __('notifications_inbox.pref_always_on') }}</span></x-slot:footer>
        </x-ui.card>
        @endif
    </div>
</div>
