{{--
    Guest booking, from the electronic menu.

    Mobile-first and JavaScript-free: three GETs and one POST, each a real URL a
    customer can go back to. Customers open this from a QR code on a table,
    often on a slow connection (docs/13-ROADMAP.md Phase 6 §26).

    Direction comes from the language registry, never a hardcoded list of RTL
    locales, and every offset is a logical property so the layout mirrors itself
    in Arabic and Kurdish (docs/07-LOCALIZATION.md §10).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A booking form has nothing to index, and a search result linking
         straight into a half-filled form is worse than none. --}}
    <meta name="robots" content="noindex">
    <title>{{ __('Book') }} · {{ $center->name }}</title>
    <style>
        :root { color-scheme: light dark; --fg:#10131a; --muted:#5b6472; --bg:#fff; --surface:#f7f8fa; --line:#e6e8ee; --accent:#6366f1; }
        @media (prefers-color-scheme: dark) {
            :root { --fg:#e8eaf0; --muted:#98a1b0; --bg:#0d0f14; --surface:#151922; --line:#242a36; }
        }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--fg); font:16px/1.6 ui-sans-serif, system-ui, "Segoe UI", Tahoma, "Noto Sans Arabic", "Noto Kufi Arabic", sans-serif; }
        .wrap { max-width:34rem; margin-inline:auto; padding:1.25rem 1rem 4rem; }
        h1 { font-size:1.35rem; margin:0 0 .25rem; }
        h2 { font-size:1.05rem; margin:1.75rem 0 .6rem; }
        p.sub { color:var(--muted); margin:0 0 1.5rem; font-size:.95rem; }
        label { display:block; font-size:.85rem; color:var(--muted); margin-bottom:.3rem; }
        select, input[type=text], input[type=tel], input[type=date] {
            width:100%; padding:.65rem .75rem; border:1px solid var(--line); border-radius:.6rem;
            background:var(--surface); color:var(--fg); font:inherit;
        }
        .field { margin-bottom:1rem; }
        .btn { display:inline-block; padding:.7rem 1.2rem; border:0; border-radius:.6rem; background:var(--accent); color:#fff; font:inherit; cursor:pointer; text-decoration:none; }
        .slots { display:flex; flex-wrap:wrap; gap:.5rem; }
        .slot { padding:.55rem .9rem; border:1px solid var(--line); border-radius:.6rem; background:var(--surface); color:var(--fg); text-decoration:none; font-variant-numeric:tabular-nums; }
        .slot.chosen { background:var(--accent); color:#fff; border-color:var(--accent); }
        .card { border:1px solid var(--line); border-radius:.75rem; padding:1rem 1.1rem; background:var(--surface); }
        .error { color:#c0392b; font-size:.9rem; }
        .ok { border:1px solid var(--accent); border-radius:.75rem; padding:1rem 1.1rem; }
        .muted { color:var(--muted); font-size:.85rem; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>{{ $center->name }}</h1>
    <p class="sub">{{ __('Book an appointment') }} · {{ __('No account needed.') }}</p>

    @if (session('booking.confirmation'))
        @php($booked = session('booking.confirmation'))
        <div class="ok">
            <h2 style="margin-top:0">{{ __('You are booked.') }}</h2>
            <p>{{ $booked['date'] }} · {{ $booked['time'] }}</p>
            {{-- No customer details echoed back. If this phone number already
                 belonged to somebody, telling this visitor their name would be
                 a disclosure (§16). --}}
            <p class="muted">{{ __('Reference') }}: {{ $booked['uuid'] }}</p>
            <p><a class="btn" href="{{ route('menu.public', ['center' => $centerKey]) }}">{{ __('Back to the menu') }}</a></p>
        </div>
    @endif

    @if (session('booking.error'))
        <p class="error">{{ session('booking.error') }}</p>
    @endif

    @if ($error)
        <p class="error">{{ $error }}</p>
    @endif

    {{-- Step 1 — what, where, when. A GET form, so every state is a URL. --}}
    <form method="GET" action="{{ route('menu.book', ['center' => $centerKey]) }}">
        @if ($branches->count() > 1)
            <div class="field">
                <label for="branch">{{ __('Branch') }}</label>
                <select id="branch" name="branch" onchange="this.form.submit()">
                    @foreach ($branches as $option)
                        <option value="{{ $option->uuid }}" @selected($option->uuid === $branch->uuid)>
                            {{ $option->name->get() }}
                        </option>
                    @endforeach
                </select>
            </div>
        @else
            <input type="hidden" name="branch" value="{{ $branch->uuid }}">
        @endif

        <div class="field">
            <label for="service">{{ __('Service') }}</label>
            <select id="service" name="service" onchange="this.form.submit()">
                <option value="">{{ __('Choose a service') }}</option>
                @foreach ($services as $option)
                    <option value="{{ $option->uuid }}" @selected($service && $option->uuid === $service->uuid)>
                        {{ $option->name->get() }} —
                        {{ $option->price($currency)->formatted() }} ·
                        {{ $option->duration_minutes }} {{ __('min') }}
                    </option>
                @endforeach
            </select>
        </div>

        @if ($service && $service->variations->isNotEmpty())
            <div class="field">
                <label for="variation">{{ __('Option') }}</label>
                <select id="variation" name="variation" onchange="this.form.submit()">
                    <option value="">{{ __('Standard') }}</option>
                    @foreach ($service->variations as $variation)
                        <option value="{{ $variation->uuid }}" @selected($variationUuid === $variation->uuid)>
                            {{ $variation->name->get() }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($service && $employees !== [])
            <div class="field">
                <label for="employee">{{ __('With') }}</label>
                <select id="employee" name="employee" onchange="this.form.submit()">
                    {{-- "Any available" is a real choice, and the default: most
                         customers do not mind, and offering it first gets them
                         more times (§5). --}}
                    <option value="">{{ __('Any available') }}</option>
                    @foreach ($employees as $option)
                        <option value="{{ $option['uuid'] }}" @selected($employeeUuid === $option['uuid'])>
                            {{ $option['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="field">
            <label for="date">{{ __('Date') }}</label>
            <input id="date" type="date" name="date" value="{{ $date }}" onchange="this.form.submit()">
        </div>

        <noscript>
            <p><button class="btn" type="submit">{{ __('Show times') }}</button></p>
        </noscript>
    </form>

    {{-- Step 2 — the times. Links, so back and forward work. --}}
    @if ($service)
        <h2>{{ __('Available times') }}</h2>

        @if ($slots === [])
            <p class="muted">{{ __('Nothing available that day. Try another date.') }}</p>
        @else
            <div class="slots">
                @foreach ($slots as $slot)
                    <a class="slot {{ $startsAt === $slot['starts_at'] ? 'chosen' : '' }}"
                       href="{{ route('menu.book', array_filter([
                            'center' => $centerKey,
                            'branch' => $branch->uuid,
                            'service' => $service->uuid,
                            'variation' => $variationUuid ?: null,
                            'employee' => $employeeUuid ?: null,
                            'date' => $date,
                            'at' => $slot['starts_at'],
                       ])) }}#confirm">{{ $slot['time'] }}</a>
                @endforeach
            </div>
        @endif
    @endif

    {{-- Step 3 — who. --}}
    @if ($service && $startsAt !== '')
        <h2 id="confirm">{{ __('Your details') }}</h2>

        <form class="card" method="POST" action="{{ route('menu.book.store', ['center' => $centerKey]) }}">
            @csrf

            <input type="hidden" name="branch" value="{{ $branch->uuid }}">
            <input type="hidden" name="service" value="{{ $service->uuid }}">
            <input type="hidden" name="variation" value="{{ $variationUuid }}">
            <input type="hidden" name="employee" value="{{ $employeeUuid }}">
            <input type="hidden" name="starts_at" value="{{ $startsAt }}">
            {{-- A browser cannot send an Idempotency-Key header, so the key
                 travels in the form. A refresh or a double-tap replays the
                 original answer instead of booking twice (§29). --}}
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

            <div class="field">
                <label for="name">{{ __('Your name') }}</label>
                {{-- Required. Without it the phone number would become the
                     customer's name, and a name is never masked (ADR-042). --}}
                <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="190">
            </div>

            <div class="field">
                <label for="phone">{{ __('Phone number') }}</label>
                <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required maxlength="32">
            </div>

            <div class="field">
                <label for="note">{{ __('Anything we should know?') }}</label>
                <input id="note" type="text" name="note" value="{{ old('note') }}" maxlength="500">
            </div>

            <button class="btn" type="submit">{{ __('Confirm booking') }}</button>
        </form>
    @endif
</div>
</body>
</html>
