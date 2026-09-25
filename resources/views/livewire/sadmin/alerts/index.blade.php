@php
    $tone = ['info' => 'info', 'warning' => 'warning', 'critical' => 'danger', 'danger' => 'danger', 'success' => 'success'];
    $icon = ['info' => 'info', 'warning' => 'alert-triangle', 'critical' => 'alert-circle', 'danger' => 'alert-circle', 'success' => 'check-circle'];
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_alerts.title')">
        @if($unreadCount > 0)
            <x-slot:actions>
                <button class="button button--secondary" type="button" wire:click="markAllRead"><x-ui.icon name="check" size="16" />{{ __('sadmin_alerts.mark_all_read') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="segmented" role="group" aria-label="{{ __('sadmin_alerts.title') }}">
        <button type="button" wire:click="setShow('all')" aria-pressed="{{ $show === 'all' ? 'true' : 'false' }}">{{ __('sadmin_alerts.all') }}<span class="segmented__count">{{ $total }}</span></button>
        <button type="button" wire:click="setShow('unread')" aria-pressed="{{ $show === 'unread' ? 'true' : 'false' }}">{{ __('sadmin_alerts.unread') }}<span class="segmented__count">{{ $unreadCount }}</span></button>
    </div>

    <section class="card card--flush" aria-live="polite">
        @if($alerts->isEmpty())
            <x-ui.empty-state :icon="$show === 'unread' ? 'check-circle' : 'notifications'" :title="$show === 'unread' ? __('sadmin_alerts.empty.unread_title') : __('sadmin_alerts.empty.title')" />
        @else
            <ul class="alert-feed">
                @foreach($alerts as $alert)
                    @php $isRead = in_array((int) $alert->id, $readIds, true); @endphp
                    <li class="alert-feed__item" @unless($isRead) data-unread="true" @endunless wire:key="alert-{{ $alert->id }}">
                        <span class="row-list__icon" data-tone="{{ $tone[$alert->severity] ?? 'info' }}" aria-hidden="true"><x-ui.icon :name="$icon[$alert->severity] ?? 'info'" /></span>
                        <div class="alert-feed__body">
                            <div class="alert-feed__head">
                                <strong>{{ $alert->title->get() }}</strong>
                                <x-ui.status :tone="$tone[$alert->severity] ?? 'info'" :label="\App\View\Label::for('severity', (string) $alert->severity)" :dot="false" />
                            </div>
                            <p>{{ $alert->body->get() }}</p>
                            <div class="alert-feed__meta">
                                <time datetime="{{ $alert->created_at?->toIso8601String() }}" title="{{ $alert->created_at?->translatedFormat('j M Y, H:i') }}">{{ $alert->created_at?->diffForHumans() }}</time>
                                @if($alert->action_url)
                                    <button class="text-button" type="button" wire:click="open({{ $alert->id }})">{{ __('sadmin_alerts.open_details') }}<x-ui.icon name="arrow-right" size="14" class="ui-icon--directional" /></button>
                                @endif
                                @if($isRead)
                                    <span class="muted">{{ __('sadmin_alerts.read') }}</span>
                                @else
                                    <button class="text-button" type="button" wire:click="markRead({{ $alert->id }})">{{ __('sadmin_alerts.mark_read') }}</button>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
