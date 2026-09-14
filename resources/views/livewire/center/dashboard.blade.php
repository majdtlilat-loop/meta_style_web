<div>
    <x-center-nav />

    <h1>{{ $tenant->name }}</h1>
    <p class="sub">
        {{ __('Signed in as :name', ['name' => $user->name]) }}
        @if ($user->is_owner) <span class="pill">{{ __('Owner') }}</span> @endif
    </p>

    <div class="card">
        <dl>
            <dt>{{ __('Subscription') }}</dt>
            <dd>
                {{ $subscription?->status->value ?? '—' }}
                @if ($trialDaysLeft !== null)
                    <span class="sub">({{ __(':days days left', ['days' => $trialDaysLeft]) }})</span>
                @endif
            </dd>

            <dt>{{ __('Access') }}</dt>
            <dd>{{ $accessLevel }}</dd>

            <dt>{{ __('Branches') }}</dt>
            <dd>
                @foreach ($branches as $branch)
                    {{ $branch->name }}@if ($branch->is_main) <span class="pill">{{ __('Main') }}</span>@endif
                    @if (! $loop->last) · @endif
                @endforeach
            </dd>

            <dt>{{ __('Your permissions') }}</dt>
            <dd>{{ $permissionCount }}</dd>

            <dt>{{ __('Plan includes') }}</dt>
            <dd>
                @forelse ($entitlements as $entitlement)
                    <span class="pill">{{ $entitlement }}</span>
                @empty
                    <span class="sub">{{ __('Nothing available on this subscription.') }}</span>
                @endforelse
            </dd>
        </dl>
    </div>

    {{--
        These are shown for orientation only. Every one of them is enforced
        server-side regardless of what this page renders
        (docs/05-ENTITLEMENTS.md §6.5).
    --}}
</div>
