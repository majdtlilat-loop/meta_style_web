{{--
    The center key names which center is being signed into — the session has no
    tenant yet. It is opaque, revocable, and authorises nothing on its own
    (docs/02-TENANCY.md §2.2, source 3).
--}}
<div>
    <h1>{{ __('Sign in') }}</h1>
    <p class="sub">{{ __('Sign in to your center.') }}</p>

    <div class="card">
        <form wire:submit="submit">
            <div class="field">
                <label for="centerKey">{{ __('Center key') }}</label>
                <input id="centerKey" type="text" wire:model="centerKey" autocomplete="off">
                @error('centerKey') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="identifier">{{ __('Email or phone') }}</label>
                <input id="identifier" type="text" wire:model="identifier" autocomplete="username">
                @error('identifier') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="password">{{ __('Password') }}</label>
                <input id="password" type="password" wire:model="password" autocomplete="current-password">
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn">{{ __('Sign in') }}</button>
        </form>
    </div>

    <p class="sub" style="margin-top:1.5rem">
        {{ __('New here?') }}
        <a href="{{ route('register') }}" wire:navigate>{{ __('Create a center') }}</a>
    </p>
</div>
