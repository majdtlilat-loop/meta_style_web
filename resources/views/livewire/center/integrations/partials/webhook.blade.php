{{-- The webhook: what the center pastes into Meta (managers only), and what was recorded. The verify token is only ever "set" / "not set". --}}
<section class="card card--flush wa-webhook" aria-labelledby="wa-webhook-title">
    <header class="card__header">
        <div><h2 id="wa-webhook-title">{{ __('manager_whatsapp.webhook.title') }}</h2></div>
        <x-ui.status :tone="$webhook['tone']" :label="$webhook['label']" />
    </header>
    <div class="card__body stack stack--sm">
        @if($webhook['url'])
            <div class="wa-copy">
                <p class="wa-copy__label">
                    {{ __('manager_whatsapp.webhook.url') }}
                    <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_whatsapp.webhook.url_tip') }}" aria-label="{{ __('manager_whatsapp.webhook.url_tip') }}"><x-ui.icon name="info" size="16" /></span>
                </p>
                <div class="copy-field">
                    <code dir="ltr">{{ $webhook['url'] }}</code>
                    <button class="button button--secondary button--sm" type="button" data-copy="{{ $webhook['url'] }}" data-copied="{{ __('ui.actions.copied') }}" aria-label="{{ __('manager_whatsapp.webhook.copy') }}">
                        <x-ui.icon name="copy" size="16" /><span class="wa-copy__text">{{ __('ui.actions.copy') }}</span>
                    </button>
                </div>
            </div>
        @endif
        <dl class="summary-list wa-summary">
            <div>
                <dt>{{ __('manager_whatsapp.webhook.verify_token') }}</dt>
                <dd><x-ui.status :tone="$webhook['verify_token_set'] ? 'success' : 'warning'" :dot="false" :label="__($webhook['verify_token_set'] ? 'manager_whatsapp.webhook.set' : 'manager_whatsapp.webhook.not_set')" /></dd>
            </div>
            <div>
                <dt>{{ __('manager_whatsapp.webhook.handshake') }}</dt>
                <dd>{{ $webhook['handshake'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>{{ __('manager_whatsapp.webhook.last_inbound') }}</dt>
                <dd>{{ $webhook['last_inbound'] ?? '—' }}</dd>
            </div>
            @if($webhook['last_rejected'])
                <div>
                    <dt>{{ __('manager_whatsapp.webhook.last_rejected') }}</dt>
                    <dd>{{ $webhook['last_rejected'] }}</dd>
                </div>
            @endif
        </dl>
    </div>
</section>
