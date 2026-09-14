{{--
    Customer sign-in and registration.

    Central: no center is bound when this loads, so the form names one. What
    resolves the center is the SUBMITTED key, never the `?center=` query string
    that merely pre-fills the field (ADR-030, ADR-036).
--}}
<div>
    <h1>{{ $mode === 'register' ? __('Create an account') : __('Sign in') }}</h1>
    <p class="sub">{{ __('Your account is with this center only.') }}</p>

    @if ($error !== '')
        <p class="error">{{ $error }}</p>
    @endif

    <form wire:submit="{{ $mode === 'register' ? 'register' : 'login' }}" class="card">
        <label for="centerKey">{{ __('Center key') }}</label>
        <input id="centerKey" type="text" wire:model="centerKey" autocomplete="off">
        @error('centerKey') <p class="error">{{ $message }}</p> @enderror

        @if ($mode === 'register')
            <label for="cname">{{ __('Your name') }}</label>
            <input id="cname" type="text" wire:model="name" autocomplete="name">
            @error('name') <p class="error">{{ $message }}</p> @enderror
        @endif

        <label for="cphone">{{ __('Phone') }}</label>
        <input id="cphone" type="tel" wire:model="phone" autocomplete="tel" placeholder="0750 123 4567">
        @error('phone') <p class="error">{{ $message }}</p> @enderror

        <label for="cpassword">{{ __('Password') }}</label>
        <input id="cpassword" type="password" wire:model="password"
            autocomplete="{{ $mode === 'register' ? 'new-password' : 'current-password' }}">
        @error('password') <p class="error">{{ $message }}</p> @enderror

        <button type="submit" class="btn">
            {{ $mode === 'register' ? __('Create account') : __('Sign in') }}
        </button>
    </form>

    <p>
        @if ($mode === 'register')
            <button type="button" wire:click="$set('mode', 'login')">{{ __('I already have an account') }}</button>
        @else
            <button type="button" wire:click="$set('mode', 'register')">{{ __('Create an account') }}</button>
        @endif
    </p>
</div>
