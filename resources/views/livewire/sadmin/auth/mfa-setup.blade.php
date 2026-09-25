<section class="auth-card auth-card--wide" aria-labelledby="setup-title">
    <x-brand.logo :context="__('ui.contexts.super_admin')" class="auth-card__brand" />
    <header class="auth-heading">
        <span class="auth-heading__icon"><x-ui.icon name="key" /></span>
        <p class="eyebrow">{{ __('platform_auth.setup_required') }}</p>
        <h1 id="setup-title">{{ __('platform_auth.setup_title') }}</h1>
        <p>{{ __('platform_auth.setup_help') }}</p>
    </header>

    @if ($recoveryCodes === [])
        <div class="field">
            <span class="field-label">{{ __('platform_auth.authentication_code') }} · {{ __('platform_auth.setup_uri') }}</span>
            <div class="copy-field">
                <code>{{ $secret }}</code>
                <button type="button" class="icon-button icon-button--sm" data-copy="{{ $secret }}" data-copied="{{ __('ui.actions.copied') }}" aria-label="{{ __('ui.actions.copy') }}"><x-ui.icon name="copy" /></button>
            </div>
        </div>
        <details class="disclosure">
            <summary>{{ __('platform_auth.setup_uri') }}</summary>
            <code class="break-anywhere ltr">{{ $provisioningUri }}</code>
        </details>
        <form wire:submit="confirm">
            <x-ui.input name="code" type="text" wire:model="code" :label="__('platform_auth.authentication_code')" autocomplete="one-time-code" inputmode="numeric" dir="ltr" class="auth-otp__input" required />
            <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="confirm">{{ __('platform_auth.confirm_mfa') }}</x-ui.button>
        </form>
    @else
        <div class="notice notice--warning" role="status">
            <x-ui.icon name="alert-triangle" />
            <div>
                <strong>{{ __('platform_auth.save_recovery') }}</strong>
                <p>{{ __('platform_auth.recovery_once') }}</p>
            </div>
        </div>
        <ul class="recovery-grid" dir="ltr">
            @foreach ($recoveryCodes as $recoveryCode)<li><code>{{ $recoveryCode }}</code></li>@endforeach
        </ul>
        <button type="button" class="button button--secondary" data-copy="{{ implode("\n", $recoveryCodes) }}" data-copied="{{ __('ui.actions.copied') }}" aria-label="{{ __('ui.actions.copy') }}"><x-ui.icon name="copy" />{{ __('ui.actions.copy') }}</button>
        <a class="button" href="{{ route(\App\Kernel\Platform\Identity\PlatformLanding::routeFor(auth('platform')->user())) }}">{{ __('platform_auth.continue_to_admin') }}</a>
    @endif
</section>
