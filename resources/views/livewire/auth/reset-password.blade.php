<section class="auth-flow" aria-labelledby="reset-title">
    <header class="auth-heading">
        <span class="auth-heading__icon"><x-ui.icon name="lock" /></span>
        <p class="eyebrow">{{ __('center_auth.common.center_account') }}</p>
        <h1 id="reset-title">{{ __('center_auth.reset.title') }}</h1>
    </header>

    <form wire:submit="submit" novalidate>
        <x-ui.input name="email" id="email" type="email" wire:model="email" :label="__('center_auth.fields.email')" autocomplete="email" required />
        <x-ui.password name="password" id="password" wire:model="password" :label="__('center_auth.fields.new_password')" :help="__('center_auth.reset.rules')" autocomplete="new-password" required />
        <x-ui.password name="passwordConfirmation" id="password-confirmation" wire:model="passwordConfirmation" :label="__('center_auth.fields.confirm_new_password')" autocomplete="new-password" required />
        <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="submit">{{ __('center_auth.actions.update_password') }}</x-ui.button>
    </form>

    <p class="auth-alt"><a href="{{ route('login') }}"><x-ui.icon name="arrow-left" size="14" class="inline" /> {{ __('center_auth.actions.return_to_sign_in') }}</a></p>
</section>
