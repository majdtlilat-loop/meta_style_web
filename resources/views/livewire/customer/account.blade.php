{{--
    The customer's own account page.

    Tenant-bound: reached only after sign-in has recorded the center in the
    session. Bookings are the customer's own, loaded by the account's customer
    id from the guard — no uuid on this page can point at anyone else's
    (docs/13-ROADMAP.md Phase 6 §27).

    Still no loyalty, no packages, no spend history: those modules do not exist.
--}}
<div>
    <h1>{{ __('Your account') }}</h1>

    @if ($notice)
        <p class="notice">{{ $notice }}</p>
    @endif

    @if ($error)
        <p class="error">{{ $error }}</p>
    @endif

    <div class="card">
        <dl>
            <dt>{{ __('Name') }}</dt>
            <dd>{{ $customer->name }}</dd>

            <dt>{{ __('Phone') }}</dt>
            {{-- Their own number, in full. Masking protects a person's details
                 from staff who do not need them, not from the person. --}}
            <dd>{{ $customer->phone_display ?? $customer->phone }}</dd>

            @if ($customer->email)
                <dt>{{ __('Email') }}</dt>
                <dd>{{ $customer->email }}</dd>
            @endif

            <dt>{{ __('Phone verified') }}</dt>
            {{-- Always "Not yet" in this release, and said plainly rather than
                 hidden: there is no verification provider (ADR-040). --}}
            <dd>{{ $account->hasVerifiedPhone() ? __('Yes') : __('Not yet') }}</dd>
        </dl>

        <p>
            <button type="button" wire:click="logout">{{ __('Sign out') }}</button>
        </p>
    </div>

    <h2>{{ $showPast ? __('Past bookings') : __('Upcoming bookings') }}</h2>

    <div class="row">
        <label class="inline">
            <input type="checkbox" wire:model.live="showPast">
            {{ __('Show past bookings') }}
        </label>
    </div>

    @forelse ($appointments as $appointment)
        <div class="card" style="margin-bottom:1rem">
            <div class="row">
                <strong>{{ $appointment['local_date'] }} · {{ $appointment['local_start'] }}</strong>
                <span class="pill">{{ __($appointment['status']) }}</span>
            </div>

            @if ($appointment['branch'])
                <p class="sub" style="margin:.25rem 0">{{ $appointment['branch']['name'] }}</p>
            @endif

            <ul>
                @foreach ($appointment['items'] as $item)
                    <li>
                        {{ $item['service'] }}
                        @if ($item['variation']) — {{ $item['variation'] }} @endif
                        @if ($item['employee']) · {{ $item['employee']['name'] }} @endif
                    </li>
                @endforeach
            </ul>

            <p>{{ $appointment['total']['formatted'] }}</p>

            @if (! $showPast && in_array($appointment['status'], ['booked', 'confirmed'], true))
                <p>
                    <button type="button"
                            wire:click="cancel('{{ $appointment['uuid'] }}')">{{ __('Cancel this booking') }}</button>
                </p>
            @endif
        </div>
    @empty
        <p class="sub">{{ $showPast ? __('No past bookings.') : __('You have no upcoming bookings.') }}</p>
    @endforelse
</div>
