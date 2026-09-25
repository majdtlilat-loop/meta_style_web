<section class="auth-card" aria-labelledby="login-title">
    <x-brand.logo :context="__('ui.contexts.super_admin')" class="auth-card__brand" />
    <header class="auth-heading">
        <p class="eyebrow">{{ __('platform_auth.administration') }}</p>
        <h1 id="login-title">{{ __('platform_auth.sign_in') }}</h1>
    </header>

    <x-ui.flash />

    <form wire:submit="submit" novalidate>
        <x-ui.input name="email" type="email" wire:model="email" :label="__('platform_auth.email')" autocomplete="username" required autofocus />
        <x-ui.password name="password" wire:model="password" :label="__('platform_auth.password')" autocomplete="current-password" required />
        {{-- Remembers the PASSWORD step only: when the platform requires two-step verification, it is still asked for every new session. --}}
        <label class="check-row"><input type="checkbox" wire:model="remember" name="remember" value="1"> <span>{{ __('ui.auth.remember_me') }}</span></label>
        <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="submit">
            <span wire:loading.remove wire:target="submit">{{ __('platform_auth.continue') }}</span>
            <span wire:loading wire:target="submit">{{ __('platform_auth.checking') }}</span>
        </x-ui.button>
    </form>

    <div class="auth-links">
        <a class="text-button" href="{{ route('superadmin.password.request') }}" wire:navigate><x-ui.icon name="key" size="16" />{{ __('platform_auth.forgot_link') }}</a>
    </div>
</section>
