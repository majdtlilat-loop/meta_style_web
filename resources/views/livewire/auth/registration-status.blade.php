{{--
    Polls while the center's database is created and migrated.

    "Ready" appears only once provisioning has actually succeeded — reporting an
    account before the database exists is the one thing this flow must not do
    (docs/02-TENANCY.md §8.2).
--}}
<div @if ($status === 'preparing') wire:poll.2s @endif>
    <h1>{{ __('Setting up your center') }}</h1>

    @if ($notice !== '')
        <p class="sub">{{ $notice }}</p>
    @endif

    @if ($status === 'preparing')
        <p class="sub">{{ __('Creating your database and applying the schema. This takes a few seconds.') }}</p>
        <div class="card">
            <p>{{ __('Preparing…') }}</p>
        </div>
    @elseif ($status === 'ready')
        <p class="sub">{{ __('Your center is ready.') }}</p>
        <div class="card">
            <label>{{ __('Center key') }}</label>
            <p><code>{{ $centerKey }}</code></p>
            <p class="sub" style="margin:.5rem 0 1.25rem">
                {{ __('You will need this to sign in. Keep it somewhere safe.') }}
            </p>
            <a href="{{ route('login') }}" class="btn" wire:navigate>{{ __('Sign in') }}</a>
        </div>
    @elseif ($status === 'failed')
        <p class="sub">{{ __('Something went wrong while setting up your center.') }}</p>
        <div class="card">
            @if ($retryable)
                {{--
                    Retry resumes; it does not start over. The password is still
                    the one submitted at registration — it is held, hashed and
                    encrypted, only for as long as this window stays open
                    (ADR-031).
                --}}
                <p>{{ __('You can pick up where it stopped. Your password and details are unchanged.') }}</p>
                <p style="margin-top:1rem">
                    <button type="button" class="btn" wire:click="retry" wire:loading.attr="disabled">
                        {{ __('Try again') }}
                    </button>
                </p>
            @else
                <p>{{ __('This registration has expired and can no longer be resumed.') }}</p>
                <p style="margin-top:1rem">
                    <a href="{{ route('register') }}" wire:navigate>{{ __('Start over') }}</a>
                </p>
            @endif
        </div>
    @elseif ($status === 'cancelled' || $status === 'abandoned')
        <p class="sub">{{ __('This registration is closed.') }}</p>
        <div class="card">
            <p>{{ __('It was not completed in time and can no longer be resumed.') }}</p>
            <p style="margin-top:1rem">
                <a href="{{ route('register') }}" wire:navigate>{{ __('Start over') }}</a>
            </p>
        </div>
    @else
        <div class="card">
            <p>{{ __('We could not find that registration.') }}</p>
        </div>
    @endif
</div>
