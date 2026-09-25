<section class="auth-card" aria-labelledby="reset-title">
    <x-brand.logo :context="__('ui.contexts.super_admin')" class="auth-card__brand" />
    <header class="auth-heading">
        <span class="auth-heading__icon"><x-ui.icon name="lock" /></span>
        <h1 id="reset-title">{{ __('platform_auth.reset_title') }}</h1>
    </header>

    <form wire:submit="submit" novalidate>
        <x-ui.input name="email" type="email" wire:model="email" :label="__('platform_auth.email')" autocomplete="username" dir="ltr" required autofocus />
        <x-ui.password name="password" wire:model="password" :label="__('platform_auth.new_password')" autocomplete="new-password" required />
        <p class="field-help">{{ __('platform_auth.password_rules') }}</p>
        <x-ui.password name="passwordConfirmation" wire:model="passwordConfirmation" :label="__('platform_auth.confirm_password')" autocomplete="new-password" required />
        <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="submit">{{ __('platform_auth.save_password') }}</x-ui.button>
    </form>

    <div class="auth-links">
        <a class="text-button" href="{{ route('superadmin.login') }}" wire:navigate><x-ui.icon name="arrow-left" size="16" />{{ __('platform_auth.back_to_sign_in') }}</a>
    </div>
</section>
