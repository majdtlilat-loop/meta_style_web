<section class="auth-card" aria-labelledby="mfa-title">
    <x-brand.logo :context="__('ui.contexts.super_admin')" class="auth-card__brand" />
    <header class="auth-heading">
        <span class="auth-heading__icon"><x-ui.icon name="shield" /></span>
        <p class="eyebrow">{{ __('platform_auth.security_check') }}</p>
        <h1 id="mfa-title">{{ __('platform_auth.mfa_confirm') }}</h1>
        <p>{{ $useRecoveryCode ? __('platform_auth.recovery_help') : __('platform_auth.code_help') }}</p>
    </header>
    <form wire:submit="submit">
        <x-ui.input name="code" type="text" wire:model="code" :label="$useRecoveryCode ? __('platform_auth.recovery_code') : __('platform_auth.authentication_code')" autocomplete="one-time-code" :inputmode="$useRecoveryCode ? 'text' : 'numeric'" dir="ltr" class="auth-otp__input" required autofocus />
        <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="submit">{{ __('platform_auth.verify') }}</x-ui.button>
    </form>
    <div class="auth-links">
        <button type="button" class="text-button" wire:click="$toggle('useRecoveryCode')">
            <x-ui.icon :name="$useRecoveryCode ? 'shield' : 'key'" size="16" />
            {{ $useRecoveryCode ? __('platform_auth.use_authenticator') : __('platform_auth.use_recovery') }}
        </button>
    </div>
</section>
