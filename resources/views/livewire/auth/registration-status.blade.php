{{--
    Polls while the center's database is created and migrated.

    "Ready" appears only once provisioning has actually succeeded — reporting an
    account before the database exists is the one thing this flow must not do
    (docs/02-TENANCY.md §8.2).
--}}
<div class="auth-flow" @if ($status === 'preparing') wire:poll.2s @endif>
    <header class="auth-heading">
        <span class="auth-heading__icon">
            @switch($status)
                @case('ready')<x-ui.icon name="check-circle" />@break
                @case('failed')<x-ui.icon name="alert-triangle" />@break
                @case('pending_verification')<x-ui.icon name="mail" />@break
                @default<x-ui.icon name="clock" />
            @endswitch
        </span>
        <p class="eyebrow">{{ __('ui.auth_story.center_eyebrow') }}</p>
        <h1>{{ __('center_auth.status.title') }}</h1>
        @if ($notice !== '')<p>{{ $notice }}</p>@endif
    </header>

    @if ($status === 'pending_verification')
        <div class="notice" role="status">
            <x-ui.icon name="mail" />
            <div><strong>{{ __('center_auth.status.check_inbox') }}</strong><p>{{ __('center_auth.status.verification_sent') }}</p></div>
        </div>
        <x-ui.button variant="secondary" icon="refresh" wire:click="resendVerification" wire:loading.attr="data-loading" wire:target="resendVerification">{{ __('center_auth.actions.resend_verification') }}</x-ui.button>
    @elseif ($status === 'preparing')
        <div class="notice" role="status">
            <span class="spinner" aria-hidden="true"></span>
            <div><strong>{{ __('center_auth.status.preparing') }}</strong><p>{{ __('center_auth.status.preparing_description') }}</p></div>
        </div>
    @elseif ($status === 'ready')
        <div class="notice notice--success" role="status">
            <x-ui.icon name="check-circle" />
            <div><strong>{{ __('center_auth.status.ready') }}</strong><p><a href="{{ $centerUrl }}" dir="ltr">{{ $centerUrl }}</a></p></div>
        </div>
        <x-ui.button :href="rtrim((string) $centerUrl, '/').'/login'" icon-after="arrow-right">{{ __('center_auth.actions.sign_in') }}</x-ui.button>
    @elseif ($status === 'failed')
        <div class="notice notice--danger" role="alert">
            <x-ui.icon name="alert-triangle" />
            <div>
                <strong>{{ __('center_auth.status.failed') }}</strong>
                {{--
                    Retry resumes; it does not start over. The password is still
                    the one submitted at registration — held, hashed and encrypted,
                    only while this window stays open (ADR-031).
                --}}
                <p>{{ $retryable ? __('center_auth.status.retry_help') : __('center_auth.status.expired') }}</p>
            </div>
        </div>
        @if ($retryable)
            <x-ui.button icon="refresh" wire:click="retry" wire:loading.attr="data-loading" wire:target="retry">{{ __('center_auth.actions.try_again') }}</x-ui.button>
        @else
            <x-ui.button variant="secondary" :href="route('register')">{{ __('center_auth.actions.start_over') }}</x-ui.button>
        @endif
    @elseif ($status === 'cancelled' || $status === 'abandoned')
        <div class="notice notice--warning" role="status">
            <x-ui.icon name="alert-circle" />
            <div><strong>{{ __('center_auth.status.closed') }}</strong><p>{{ __('center_auth.status.closed_help') }}</p></div>
        </div>
        <x-ui.button variant="secondary" :href="route('register')">{{ __('center_auth.actions.start_over') }}</x-ui.button>
    @else
        <div class="notice notice--warning" role="status">
            <x-ui.icon name="alert-circle" />
            <p>{{ __('center_auth.status.not_found') }}</p>
        </div>
    @endif
</div>
