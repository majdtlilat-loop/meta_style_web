<section class="card card--flush" aria-labelledby="notification-preferences-title">
    <header class="card__header">
        <div>
            <h2 id="notification-preferences-title">{{ __('manager_settings.notifications.title') }}</h2>
        </div>
    </header>
    <div class="card__body stack stack--sm">
        <x-ui.notice :message="$notice" tone="success" dismiss="$set('notice', '')" />
    </div>
    @foreach ($rows as $row)
        <div class="setting-row" wire:key="preference-{{ $row['key'] }}">
            <div>
                <label for="preference-{{ $row['key'] }}"><strong>{{ $row['label'] }}</strong></label>
            </div>
            <input id="preference-{{ $row['key'] }}" type="checkbox" class="switch" role="switch" @checked($row['on'])
                   wire:click="toggle('{{ $row['key'] }}')" wire:loading.attr="disabled" wire:target="toggle('{{ $row['key'] }}')">
        </div>
    @endforeach
    <footer class="card__footer">
        <p class="field-help">{{ __('manager_settings.notifications.always_on') }}</p>
        @if ($inboxUrl)
            <a class="button button--ghost button--sm" href="{{ $inboxUrl }}" wire:navigate><x-ui.icon name="bell" size="16" />{{ __('manager_settings.notifications.open_inbox') }}</a>
        @endif
    </footer>
</section>
