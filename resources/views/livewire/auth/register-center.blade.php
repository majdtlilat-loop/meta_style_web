<div>
    <h1>{{ __('Create your center') }}</h1>
    <p class="sub">{{ __('Five details now. Everything else once you are in.') }}</p>

    <div class="card">
        <form wire:submit="submit">
            <div class="field">
                <label for="centerName">{{ __('Center name') }}</label>
                <input id="centerName" type="text" wire:model="centerName" autocomplete="organization">
                @error('centerName') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="ownerName">{{ __('Your name') }}</label>
                <input id="ownerName" type="text" wire:model="ownerName" autocomplete="name">
                @error('ownerName') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="email">{{ __('Email address') }}</label>
                <input id="email" type="email" wire:model="email" autocomplete="email">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="password">{{ __('Password') }}</label>
                <input id="password" type="password" wire:model="password" autocomplete="new-password">
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="locale">{{ __('Language') }}</label>
                <select id="locale" wire:model="locale">
                    @foreach (config('localization.languages') as $code => $language)
                        <option value="{{ $code }}">{{ $language['name_native'] }}</option>
                    @endforeach
                </select>
                @error('locale') <p class="error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn" wire:loading.attr="disabled">
                <span wire:loading.remove>{{ __('Create center') }}</span>
                <span wire:loading>{{ __('Working…') }}</span>
            </button>
        </form>
    </div>

    <p class="sub" style="margin-top:1.5rem">
        {{ __('Already have a center?') }}
        <a href="{{ route('login') }}" wire:navigate>{{ __('Sign in') }}</a>
    </p>
</div>
