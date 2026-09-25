<section class="auth-flow" aria-labelledby="forgot-title">
    <header class="auth-heading">
        <span class="auth-heading__icon"><x-ui.icon name="key" /></span>
        <p class="eyebrow">{{ __('center_auth.common.center_account') }}</p>
        <h1 id="forgot-title">{{ __('center_auth.forgot.title') }}</h1>
    </header>

    @if($sent)
        <div class="notice notice--success" role="status">
            <x-ui.icon name="mail" />
            <p>{{ __('center_auth.forgot.sent') }}</p>
        </div>
    @else
        <form wire:submit="submit" novalidate>
            <x-ui.input name="email" id="reset-email" type="email" wire:model="email" :label="__('center_auth.fields.email')" autocomplete="email" required autofocus />
            <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="submit">{{ __('center_auth.actions.send_reset_link') }}</x-ui.button>
        </form>
    @endif

    <p class="auth-alt"><a href="{{ route('login') }}"><x-ui.icon name="arrow-left" size="14" class="inline" /> {{ __('center_auth.actions.return_to_sign_in') }}</a></p>
</section>
