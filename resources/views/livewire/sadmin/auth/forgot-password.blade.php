<section class="auth-card" aria-labelledby="forgot-title">
    <x-brand.logo :context="__('ui.contexts.super_admin')" class="auth-card__brand" />
    <header class="auth-heading">
        <span class="auth-heading__icon"><x-ui.icon name="key" /></span>
        <h1 id="forgot-title">{{ __('platform_auth.forgot_title') }}</h1>
    </header>

    @if($sent)
        <div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ __('platform_auth.link_sent') }}</p></div>
    @else
        <form wire:submit="submit" novalidate>
            <x-ui.input name="email" type="email" wire:model="email" :label="__('platform_auth.email')" autocomplete="username" dir="ltr" required autofocus />
            <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="submit">{{ __('platform_auth.send_link') }}</x-ui.button>
        </form>
    @endif

    <div class="auth-links">
        <a class="text-button" href="{{ route('superadmin.login') }}" wire:navigate><x-ui.icon name="arrow-left" size="16" />{{ __('platform_auth.back_to_sign_in') }}</a>
    </div>
</section>
