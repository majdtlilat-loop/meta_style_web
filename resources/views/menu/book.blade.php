{{--
    Guest booking, from the electronic menu.

    Mobile-first and JavaScript-free: three GETs and one POST, each a real URL a
    customer can go back to. Customers open this from a QR code on a table,
    often on a slow connection (docs/13-ROADMAP.md Phase 6 §26).

    Direction comes from the language registry, never a hardcoded list of RTL
    locales, and every offset is a logical property so the layout mirrors itself
    in Arabic and Kurdish (docs/07-LOCALIZATION.md §10).

    The center's booking-page appearance (PublicPageAppearance::booking) frames
    the flow: validated colours as custom properties, enum classes on <body>,
    and plain-text copy already resolved to this language. The flow itself —
    what is offered, what is posted — is untouched by it. The Manager preview
    renders this same template with `$preview`, which disables every submit.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A booking form has nothing to index, and a search result linking
         straight into a half-filled form is worse than none. --}}
    <meta name="robots" content="noindex">
    <title>{{ $appearance['title'] }} · {{ $center->name }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --fg:#10131a; --muted:#5b6472; --bg:#fff; --surface:#f7f8fa; --line:#e6e8ee;
            @foreach ($appearance['vars'] as $property => $value)
            {{ $property }}: {{ $value }};
            @endforeach
        }
        @media (prefers-color-scheme: dark) {
            .bg-auto { --fg:#e8eaf0; --muted:#98a1b0; --bg:#0d0f14; --surface:#151922; --line:#242a36; }
        }
        .bg-light { color-scheme: light; }
        .bg-dark { color-scheme: dark; --fg:#e8eaf0; --muted:#98a1b0; --bg:#0d0f14; --surface:#151922; --line:#242a36; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--fg); font:16px/1.6 ui-sans-serif, system-ui, Segoe UI, Tahoma, Noto Sans Arabic, Noto Kufi Arabic, sans-serif; }
        a { color: var(--accent); }
        .wrap { max-width:34rem; margin-inline:auto; padding:1.25rem 1rem 4rem; }
        .preview-banner { position:sticky; top:0; z-index:5; margin:0; padding:.5rem 1rem; text-align:center; background:#10131a; color:#fff; font:600 .8rem/1.4 system-ui, sans-serif; }
        header.page { margin:0 0 1.5rem; }
        header.page .logo { display:block; max-height:3rem; width:auto; max-width:10rem; object-fit:contain; margin-bottom:.75rem; }
        header.page .center { margin:0; font-size:.9rem; font-weight:600; color:var(--muted); }
        header.page h1 { font-size:1.4rem; margin:.1rem 0 .25rem; line-height:1.25; }
        header.page p.sub { color:var(--muted); margin:0; font-size:.95rem; white-space:pre-line; }
        .header-banner header.page, .header-gradient header.page { background:var(--header-bg); color:var(--on-primary); padding:1.5rem 1.25rem; border-radius:.9rem; }
        .header-banner header.page .center, .header-gradient header.page .center, .header-banner header.page p.sub, .header-gradient header.page p.sub { color:inherit; opacity:.85; }
        h2 { font-size:1.05rem; margin:1.75rem 0 .6rem; display:flex; align-items:center; gap:.5rem; }
        .steps-numbered h2.step::before {
            counter-increment: step; content: counter(step);
            display:inline-grid; place-items:center; width:1.6rem; height:1.6rem; border-radius:50%;
            background:var(--accent); color:var(--on-accent); font-size:.85rem; flex:0 0 auto;
        }
        main { counter-reset: step; }
        label { display:block; font-size:.85rem; color:var(--muted); margin-bottom:.3rem; }
        select, input[type=text], input[type=tel], input[type=date] {
            width:100%; padding:.65rem .75rem; border:1px solid var(--line); border-radius:.6rem;
            background:var(--surface); color:var(--fg); font:inherit;
        }
        .field { margin-bottom:1rem; }
        .btn { display:inline-block; padding:.7rem 1.2rem; border:2px solid var(--accent); border-radius:.6rem; background:var(--accent); color:var(--on-accent); font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
        .cta-outline .btn { background:transparent; color:var(--accent); }
        .cta-pill .btn { border-radius:999px; }
        .btn[disabled] { opacity:.7; cursor:not-allowed; }
        .slots { display:flex; flex-wrap:wrap; gap:.5rem; }
        .slot { padding:.55rem .9rem; border:1px solid var(--line); border-radius:.6rem; background:var(--surface); color:var(--fg); text-decoration:none; font-variant-numeric:tabular-nums; }
        .slot.chosen { background:var(--accent); color:var(--on-accent); border-color:var(--accent); }
        .card { border:1px solid var(--line); border-radius:.75rem; padding:1rem 1.1rem; background:var(--surface); }
        .error { color:#c0392b; font-size:.9rem; }
        .ok { border:1px solid var(--accent); border-radius:.75rem; padding:1rem 1.1rem; }
        .ok h2 { margin-top:0; }
        .ok .message { white-space:pre-line; }
        .ok .code { font:600 1.1rem/1.4 ui-monospace, SFMono-Regular, Consolas, monospace; letter-spacing:.06em; }
        .muted { color:var(--muted); font-size:.85rem; }
        .policies { margin-top:2.5rem; padding-top:1.25rem; border-top:1px solid var(--line); font-size:.9rem; color:var(--muted); }
        .policies h3 { font-size:.95rem; margin:.75rem 0 .25rem; color:var(--fg); }
        .policies p { margin:0; white-space:pre-line; }
    </style>
</head>
<body class="{{ implode(' ', $appearance['classes']) }}">
@if ($preview ?? false)
    <p class="preview-banner" role="note">{{ __('menu_public.booking.preview_banner') }}</p>
@endif
<div class="wrap">
    <header class="page">
        @if ($appearance['logo'])
            <img class="logo" src="{{ $appearance['logo'] }}" alt="{{ $center->name }}" height="48">
        @endif
        <p class="center">{{ $center->name }}</p>
        <h1>{{ $appearance['title'] }}</h1>
        <p class="sub">{{ $appearance['intro'] ?? __('menu_public.booking.no_account') }}</p>
    </header>

    <main>
    @if (session('booking.confirmation'))
        <div class="ok" role="status">
            <h2>{{ __('menu_public.booking.booked') }}</h2>
            @if ($appearance['confirmation_message'])
                <p class="message">{{ $appearance['confirmation_message'] }}</p>
            @endif
            <p dir="ltr">{{ session('booking.confirmation.date') }} · {{ session('booking.confirmation.time') }}</p>
            {{-- No customer details echoed back. If this phone number already
                 belonged to somebody, telling this visitor their name would be
                 a disclosure (§16). The reference and the one-time code are the
                 two things a guest needs to keep (docs/24 §§3, 11). --}}
            @if (session('booking.confirmation.reference'))
                <p class="muted">{{ __('menu_public.booking.reference') }}: <strong dir="ltr">{{ session('booking.confirmation.reference') }}</strong></p>
            @endif
            @if (session('booking.confirmation.verification_code'))
                <p class="muted">{{ __('menu_public.booking.code') }}</p>
                <p class="code" dir="ltr">{{ session('booking.confirmation.verification_code') }}</p>
                <p class="muted">{{ __('menu_public.booking.code_help') }}</p>
            @endif
            <p><a class="btn" href="{{ route('menu.public', ['center' => $centerKey]) }}">{{ __('menu_public.booking.back') }}</a></p>
        </div>
    @endif

    @if (session('booking.error'))
        <p class="error" role="alert">{{ session('booking.error') }}</p>
    @endif

    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif

    @if ($branch === null)
        <p class="muted">{{ __('menu_public.booking.no_branch') }}</p>
    @else
    {{-- Step 1 — what, where, when. A GET form, so every state is a URL. --}}
    <h2 class="step">{{ __('menu_public.booking.step_service') }}</h2>
    <form method="GET" action="{{ ($preview ?? false) ? '#' : route('menu.book', ['center' => $centerKey]) }}" @if ($preview ?? false) onsubmit="return false" @endif>
        @if ($branches->count() > 1)
            <div class="field">
                <label for="branch">{{ __('menu_public.booking.branch') }}</label>
                <select id="branch" name="branch" @unless ($preview ?? false) onchange="this.form.submit()" @endunless>
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
            <label for="service">{{ __('menu_public.booking.service') }}</label>
            <select id="service" name="service" @unless ($preview ?? false) onchange="this.form.submit()" @endunless>
                <option value="">{{ __('menu_public.booking.choose_service') }}</option>
                @foreach ($services as $option)
                    <option value="{{ $option->uuid }}" @selected($service && $option->uuid === $service->uuid)>
                        {{ $option->name->get() }}@if ($appearance['show_prices']) — {{ $option->price($currency)->formatted() }}@endif @if ($appearance['show_duration']) · {{ __('menu_public.minutes', ['count' => $option->duration_minutes]) }}@endif
                    </option>
                @endforeach
            </select>
        </div>

        @if ($service && $service->variations->isNotEmpty())
            <div class="field">
                <label for="variation">{{ __('menu_public.booking.option') }}</label>
                <select id="variation" name="variation" onchange="this.form.submit()">
                    <option value="">{{ __('menu_public.booking.standard') }}</option>
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
                <label for="employee">{{ __('menu_public.booking.with') }}</label>
                <select id="employee" name="employee" onchange="this.form.submit()">
                    {{-- "Any available" is a real choice, and the default: most
                         customers do not mind, and offering it first gets them
                         more times (§5). --}}
                    <option value="">{{ __('menu_public.booking.any') }}</option>
                    @foreach ($employees as $option)
                        <option value="{{ $option['uuid'] }}" @selected($employeeUuid === $option['uuid'])>
                            {{ $option['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="field">
            <label for="date">{{ __('menu_public.booking.date') }}</label>
            <input id="date" type="date" name="date" value="{{ $date }}" @unless ($preview ?? false) onchange="this.form.submit()" @endunless>
        </div>

        <noscript>
            <p><button class="btn" type="submit">{{ __('menu_public.booking.show_times') }}</button></p>
        </noscript>
    </form>

    {{-- Step 2 — the times. Links, so back and forward work. --}}
    @if ($service)
        <h2 class="step">{{ __('menu_public.booking.times') }}</h2>

        @if ($slots === [])
            <p class="muted">{{ __('menu_public.booking.nothing') }}</p>
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
                       ])) }}#confirm" dir="ltr">{{ $slot['time'] }}</a>
                @endforeach
            </div>
        @endif
    @elseif ($preview ?? false)
        <h2 class="step">{{ __('menu_public.booking.times') }}</h2>
        <p class="muted">{{ __('menu_public.booking.preview_times') }}</p>
    @endif

    {{-- Step 3 — who. --}}
    @if (($service && $startsAt !== '') || ($preview ?? false))
        <h2 class="step" id="confirm">{{ __('menu_public.booking.details') }}</h2>

        <form class="card" method="POST" action="{{ ($preview ?? false) ? '#' : route('menu.book.store', ['center' => $centerKey]) }}" @if ($preview ?? false) onsubmit="return false" @endif>
            @csrf

            <input type="hidden" name="branch" value="{{ $branch->uuid }}">
            <input type="hidden" name="service" value="{{ $service?->uuid }}">
            <input type="hidden" name="variation" value="{{ $variationUuid }}">
            <input type="hidden" name="employee" value="{{ $employeeUuid }}">
            <input type="hidden" name="starts_at" value="{{ $startsAt }}">
            {{-- A browser cannot send an Idempotency-Key header, so the key
                 travels in the form. A refresh or a double-tap replays the
                 original answer instead of booking twice (§29). --}}
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

            <div class="field">
                <label for="name">{{ __('menu_public.booking.name') }}</label>
                {{-- Required. Without it the phone number would become the
                     customer's name, and a name is never masked (ADR-042). --}}
                <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="190" autocomplete="name">
            </div>

            <div class="field">
                <label for="phone">{{ __('menu_public.booking.phone') }}</label>
                <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required maxlength="32" autocomplete="tel" dir="ltr">
            </div>

            <div class="field">
                <label for="note">{{ __('menu_public.booking.note') }}</label>
                <input id="note" type="text" name="note" value="{{ old('note') }}" maxlength="500">
            </div>

            <button class="btn" type="submit" @disabled($preview ?? false)>{{ $appearance['cta_label'] }}</button>
        </form>
    @endif

    @if ($preview ?? false)
        <div class="ok" style="margin-top:1.5rem">
            <h2>{{ __('menu_public.booking.booked') }}</h2>
            @if ($appearance['confirmation_message'])
                <p class="message">{{ $appearance['confirmation_message'] }}</p>
            @endif
            <p class="muted">{{ __('menu_public.booking.preview_confirmation') }}</p>
        </div>
    @endif
    @endif

    @if ($appearance['policies']['cancellation'] || $appearance['policies']['terms'])
        <section class="policies" aria-label="{{ __('menu_public.booking.policies') }}">
            @if ($appearance['policies']['cancellation'])
                <h3>{{ __('menu_public.booking.cancellation') }}</h3>
                <p>{{ $appearance['policies']['cancellation'] }}</p>
            @endif
            @if ($appearance['policies']['terms'])
                <h3>{{ __('menu_public.booking.terms') }}</h3>
                <p>{{ $appearance['policies']['terms'] }}</p>
            @endif
        </section>
    @endif
    </main>
</div>
</body>
</html>
