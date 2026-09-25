{{--
    The customer's own account page.

    Tenant-bound: reached only after sign-in has recorded the center in the
    session. Bookings are the customer's own, loaded by the account's customer
    id from the guard — no uuid on this page can point at anyone else's
    (docs/13-ROADMAP.md Phase 6 §27).

    Points, memberships and packages are the customer's own, from an
    allow-list: names, what is left, when it ends — never a sale, a staff name
    or a reason (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §19). Still no spend
    history.
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

    @if ($benefits['loyalty'] !== null || $benefits['memberships'] !== [] || $benefits['packages'] !== [])
        <h2>{{ __('customer_benefits.title') }}</h2>

        @if ($benefits['loyalty'] !== null)
            <div class="card">
                <h3>{{ __('customer_benefits.points') }}</h3>
                <p>
                    <strong>{{ __('customer_benefits.points_available', ['n' => $benefits['loyalty']['available_points']]) }}</strong>
                    @if ($benefits['loyalty']['tier'] !== null)
                        · {{ __('customer_benefits.tier', ['tier' => $benefits['loyalty']['tier']['name']]) }}
                    @endif
                </p>
                @if ($benefits['loyalty']['recent'] !== [])
                    <h4>{{ __('customer_benefits.recent') }}</h4>
                    <ul>
                        @foreach ($benefits['loyalty']['recent'] as $row)
                            <li>
                                <span dir="ltr">{{ $row['date'] }}</span> ·
                                {{ __('customer_benefits.kind_'.$row['kind']) }}
                                {{ $row['direction'] === 'in' ? '+' : '−' }}{{ $row['points'] }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if ($benefits['memberships'] !== [])
            <h3>{{ __('customer_benefits.memberships') }}</h3>
            @foreach ($benefits['memberships'] as $membership)
                <div class="card">
                    <strong>{{ $membership['name'] }}</strong>
                    <div>
                        @if ($membership['state'] === 'upcoming')
                            {{ __('customer_benefits.membership_upcoming', ['date' => $membership['first_day'], 'until' => $membership['last_day']]) }}
                        @else
                            {{ __('customer_benefits.membership_active', ['date' => $membership['last_day']]) }}
                        @endif
                    </div>
                    <ul>
                        @foreach ($membership['benefits'] as $benefit)
                            @php($service = $benefit['all_services'] ? __('customer_benefits.every_service') : $benefit['service_name'])
                            <li>
                                @if ($benefit['percent'] !== null)
                                    {{ __('customer_benefits.percent_off', ['percent' => $benefit['percent'], 'service' => $service]) }}
                                @else
                                    {{ __('customer_benefits.amount_off', ['amount' => $benefit['amount']['formatted'] ?? '', 'service' => $service]) }}
                                @endif
                                @if ($benefit['uses_left'] !== null)
                                    · {{ __('customer_benefits.uses_left', ['n' => $benefit['uses_left']]) }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        @endif

        @if ($benefits['packages'] !== [])
            <h3>{{ __('customer_benefits.packages') }}</h3>
            @foreach ($benefits['packages'] as $package)
                <div class="card">
                    <strong>{{ $package['name'] }}</strong>
                    <div>{{ __('customer_benefits.package_until', ['date' => $package['last_day']]) }}</div>
                    <ul>
                        @foreach ($package['items'] as $item)
                            <li>{{ __('customer_benefits.sessions_left', ['name' => $item['name'], 'n' => $item['left'], 'm' => $item['allocated']]) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        @endif
    @endif

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

            {{-- The quotable reference. Public, and it authenticates nothing —
                 which is exactly why it is safe to print here
                 (docs/24-BOOKING-VERIFICATION.md §1). --}}
            @if ($appointment['reference'])
                <p class="sub">{{ __('Booking reference') }}: <strong>{{ $appointment['reference'] }}</strong></p>
            @endif

            @if (! $showPast && in_array($appointment['status'], ['booked', 'confirmed'], true))
                <p>
                    <button type="button"
                            wire:click="cancel('{{ $appointment['uuid'] }}')">{{ __('Cancel this booking') }}</button>

                    {{--
                        REGENERATE, never "show".

                        The code is stored as a keyed digest and the raw value
                        was handed over once, when the booking was made. There
                        is nothing to display, so the only honest action is a
                        new code — and issuing one retires the old, which is
                        what a customer wants when they think somebody else has
                        seen it (§§8, 11).
                    --}}
                    <button type="button"
                            wire:click="regenerateCode('{{ $appointment['uuid'] }}')">{{ __('New verification code') }}</button>
                </p>

                @if ($issuedCode !== '' && $issuedFor === $appointment['reference'])
                    <p role="status" class="issued-code">
                        {{ __('Write this down now — it is shown only once.') }}
                        <strong>{{ $issuedCode }}</strong>
                    </p>
                @endif
            @endif
        </div>
    @empty
        <p class="sub">{{ $showPast ? __('No past bookings.') : __('You have no upcoming bookings.') }}</p>
    @endforelse

    {{--
        Phase 12: what the center has told this customer, and any visit they can
        still review.
    
        docs/23-NOTIFICATIONS.md §16, docs/22-REVIEWS.md §40. The invitation is
        named by its own uuid: a signed-in customer needs no capability secret, so
        none is on this page. Every message is built at read time in their own
        language and escaped here.
    --}}
    @if ($invitations !== [])
        <h2>{{ __('review_public.title') }}</h2>
    
        @foreach ($invitations as $invitation)
            <div class="card" wire:key="inv-{{ $invitation['id'] }}">
                <p>{{ __('review_public.intro', ['branch' => $invitation['branch'], 'date' => $invitation['visited_on']]) }}</p>
    
                <ul>
                    @foreach ($invitation['stages'] as $stage)
                        <li>
                            {{ $stage['service'] }}
                            @if ($stage['employee']) · {{ $stage['employee'] }} @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif
    
    <h2>{{ __('notifications_inbox.title') }}</h2>
    
    <p class="sub">{{ __('notifications_inbox.unread') }}: {{ $unread }}</p>
    
    @forelse ($notifications as $notification)
        <div class="card" wire:key="note-{{ $notification['id'] }}">
            <p>{{ $notification['message'] }}</p>
            <p class="sub">{{ $notification['created_at'] }}</p>
    
            @if ($notification['read_at'] === null)
                <button type="button" wire:click="readNotification('{{ $notification['id'] }}')">
                    {{ __('notifications_inbox.mark_read') }}
                </button>
            @endif
        </div>
    @empty
        <p class="sub">{{ __('notifications_inbox.empty') }}</p>
    @endforelse
</div>
