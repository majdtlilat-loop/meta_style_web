<div class="auth-flow">
    <header class="auth-heading">
        <p class="eyebrow">{{ __('center_auth.common.center_account') }}</p>
        <h1>{{ __('center_auth.login.title') }}</h1>
    </header>

    {{-- Set by the password reset, shown once. --}}
    <x-ui.flash key="password_reset" />

    <form wire:submit="submit" novalidate>
        <x-ui.input name="identifier" id="identifier" type="text" wire:model="identifier" :label="__('center_auth.fields.email_or_phone')" autocomplete="username" required autofocus />
        <x-ui.password name="password" id="password" wire:model="password" :label="__('center_auth.fields.password')" autocomplete="current-password" required>
            <x-slot:labelRow><a class="auth-inline-link" href="{{ route('password.request') }}">{{ __('center_auth.actions.forgot_password') }}</a></x-slot:labelRow>
        </x-ui.password>
        <label class="check-row"><input type="checkbox" wire:model="remember" name="remember" value="1"> <span>{{ __('ui.auth.remember_me') }}</span></label>
        <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="submit">{{ __('center_auth.actions.sign_in') }}</x-ui.button>
    </form>

    @if($registerUrl)
        <p class="auth-alt">{{ __('center_auth.login.new_here') }} <a href="{{ $registerUrl }}">{{ __('center_auth.actions.create_center') }}</a></p>
    @endif
</div>
