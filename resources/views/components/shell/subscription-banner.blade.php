@props(['banner'])
{{--
    The one account-wide subscription line (App\View\Manager\SubscriptionBanner):
    trial days left, past due with its grace date, suspended (read-only),
    expired or cancelled. Every value arrives translated.
--}}
<div {{ $attributes->class(['subscription-banner']) }} data-tone="{{ $banner['tone'] }}" data-status="{{ $banner['status'] }}" role="{{ $banner['tone'] === 'danger' ? 'alert' : 'status' }}">
    <div class="subscription-banner__inner">
        <span class="subscription-banner__icon" aria-hidden="true"><x-ui.icon :name="$banner['icon']" size="18" /></span>
        <p class="subscription-banner__message">{{ $banner['message'] }}</p>
        @if($banner['action'] || $banner['support'])
            <div class="subscription-banner__actions">
                @if($banner['support'])
                    <a class="subscription-banner__link" href="{{ $banner['support']['href'] }}" wire:navigate>{{ $banner['support']['label'] }}</a>
                @endif
                @if($banner['action'])
                    <a class="button button--sm subscription-banner__cta" href="{{ $banner['action']['href'] }}" wire:navigate>{{ $banner['action']['label'] }}<x-ui.icon name="arrow-right" size="14" /></a>
                @endif
            </div>
        @endif
    </div>
</div>
