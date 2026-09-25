<section class="auth-flow" aria-labelledby="activate-title">
    <header class="auth-heading">
        <span class="auth-heading__icon"><x-ui.icon name="key" /></span>
        <p class="eyebrow">{{ __('center_auth.common.center_account') }}</p>
        <h1 id="activate-title">{{ $valid && $firstName !== '' ? __('manager_staff.activation.welcome', ['name' => $firstName]) : __('manager_staff.activation.title') }}</h1>
        @unless($valid)<p>{{ __('manager_staff.activation.invalid') }}</p>@endunless
    </header>

    @if($valid)
        <form wire:submit="submit" novalidate>
            <x-ui.password name="password" id="password" wire:model="password" :label="__('manager_staff.activation.password')" autocomplete="new-password" required :help="__('manager_staff.activation.password_help')" />
            <x-ui.password name="passwordConfirmation" id="password-confirmation" wire:model="passwordConfirmation" :label="__('manager_staff.activation.confirm')" autocomplete="new-password" required />
            <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="submit">{{ __('manager_staff.activation.submit') }}</x-ui.button>
        </form>
    @else
        <div class="notice" data-tone="warning" role="status"><x-ui.icon name="alert-triangle" /><p>{{ __('manager_staff.activation.invalid_help') }}</p></div>
    @endif

    <p class="auth-alt"><a href="{{ route('login') }}"><x-ui.icon name="arrow-left" size="14" class="inline" /> {{ __('center_auth.actions.return_to_sign_in') }}</a></p>
</section>
