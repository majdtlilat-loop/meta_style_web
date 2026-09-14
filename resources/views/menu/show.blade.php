@php
    /** @var \App\Modules\Menu\Domain\MenuPresentation $presentation */
    $presentation = $menu['presentation'];
    $currency = $menu['currency'];
    $theme = $presentation->theme;

    $fonts = [
        'sans' => 'ui-sans-serif, system-ui, "Segoe UI", Tahoma, "Noto Sans Arabic", "Noto Kufi Arabic", sans-serif',
        'serif' => 'ui-serif, Georgia, "Noto Naskh Arabic", "Times New Roman", serif',
        'display' => '"Segoe UI Semibold", ui-sans-serif, system-ui, "Noto Kufi Arabic", sans-serif',
    ];

    $radius = ['square' => '0', 'rounded' => '.75rem', 'pill' => '1.75rem'];
    $gap = ['compact' => '.6rem', 'comfortable' => '1rem', 'spacious' => '1.5rem'];
    $dark = ($theme['background'] ?? 'light') === 'dark';
@endphp
    <!DOCTYPE html>
{{--
    The public electronic menu.

    Mobile-first: customers open this from a phone, usually from a QR code on a
    table. Direction comes from the language registry, never a hardcoded list of
    RTL locales (docs/07-LOCALIZATION.md §10).

    Every value interpolated into the stylesheet below has already been
    validated against the code-owned catalog in config/menu.php — a center
    cannot put arbitrary CSS here (ADR-036 / Phase 4 §14).
--}}
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>{{ $center->name }}</title>
    <style>
        :root {
            --primary: {{ $theme['primary'] ?? '#111827' }};
            --accent: {{ $theme['accent'] ?? '#6366f1' }};
            --bg: {{ $dark ? '#0d0f14' : '#ffffff' }};
            --surface: {{ $dark ? '#151922' : '#f7f8fa' }};
            --fg: {{ $dark ? '#e8eaf0' : '#10131a' }};
            --muted: {{ $dark ? '#98a1b0' : '#5b6472' }};
            --line: {{ $dark ? '#242a36' : '#e6e8ee' }};
            --radius: {{ $radius[$theme['corners'] ?? 'rounded'] ?? '.75rem' }};
            --gap: {{ $gap[$theme['density'] ?? 'comfortable'] ?? '1rem' }};
            --font: {{ $fonts[$theme['font'] ?? 'sans'] ?? $fonts['sans'] }};
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--fg);
            font: 16px/1.6 var(--font);
            -webkit-text-size-adjust: 100%;
        }

        .wrap { max-width: 46rem; margin-inline: auto; padding: 1.25rem 1rem 4rem; }

        header.hero { padding: 2rem 0 1.25rem; border-bottom: 1px solid var(--line); }
        header.hero h1 { margin: 0; font-size: 1.75rem; letter-spacing: -.01em; color: var(--primary); }
        header.hero p { margin: .35rem 0 0; color: var(--muted); }

        nav.langs { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
        nav.langs a {
            font-size: .875rem; text-decoration: none; color: var(--muted);
            border: 1px solid var(--line); border-radius: var(--radius); padding: .25rem .7rem;
        }
        nav.langs a[aria-current="true"] { color: var(--bg); background: var(--accent); border-color: var(--accent); }

        section { margin-top: 2.25rem; }
        section > h2 { font-size: 1.15rem; margin: 0 0 var(--gap); color: var(--primary); }

        .cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: var(--gap); }
        .cat {
            border: 1px solid var(--line); border-radius: var(--radius);
            padding: .85rem 1rem; background: var(--surface); text-decoration: none; color: inherit;
        }
        .cat strong { display: block; }

        .services { display: grid; gap: var(--gap); }

        .service {
            border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface);
            padding: var(--gap); display: grid; gap: .5rem;
        }
        {{-- `elevated` and `flat` differ only in shadow and border; both are in the catalog. --}}
        @if (($theme['card_style'] ?? 'flat') === 'elevated')
            .service, .cat { box-shadow: 0 1px 3px rgb(0 0 0 / 12%); border-color: transparent; }
        @elseif (($theme['card_style'] ?? 'flat') === 'flat')
            .service, .cat { border-color: transparent; }
        @endif

        .service h3 { margin: 0; font-size: 1.05rem; }
        .service .meta { display: flex; justify-content: space-between; gap: 1rem; align-items: baseline; }
        .service .price { font-weight: 600; color: var(--primary); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .service .book { display: inline-block; margin-top: .5rem; padding: .5rem 1rem; border-radius: var(--radius); background: var(--accent); color: #fff; text-decoration: none; font-size: .9rem; }
        .service .desc { color: var(--muted); font-size: .925rem; margin: 0; }

        .service img { width: 100%; max-width: 100%; height: auto; border-radius: var(--radius); display: block; }

        ul.variants { list-style: none; margin: .25rem 0 0; padding: 0; display: grid; gap: .3rem; }
        ul.variants li { display: flex; justify-content: space-between; gap: 1rem; font-size: .925rem; color: var(--muted); }

        .addons { font-size: .875rem; color: var(--muted); margin: 0; }

        .people { display: flex; flex-wrap: wrap; gap: .5rem; }
        .people span { border: 1px solid var(--line); border-radius: var(--radius); padding: .3rem .8rem; font-size: .9rem; }

        footer { margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--line); color: var(--muted); font-size: .925rem; }
        footer a { color: var(--accent); }

        table.hours { border-collapse: collapse; font-size: .925rem; }
        table.hours td { padding: .15rem .75rem .15rem 0; }
        [dir="rtl"] table.hours td { padding: .15rem 0 .15rem .75rem; }

        @media (min-width: 40rem) {
            .services { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
<div class="wrap">

    @if ($presentation->hasSection('hero'))
        <header class="hero">
            <h1>{{ $center->name }}</h1>
            @if ($menu['branch'])
                <p>{{ $menu['branch']->name?->get($locale) }}</p>
            @endif

            @if (count($locales) > 1)
                <nav class="langs" aria-label="{{ __('Language') }}">
                    @foreach ($locales as $code)
                        <a href="?locale={{ $code }}@if(request('branch'))&branch={{ request('branch') }}@endif"
                           lang="{{ $code }}"
                           aria-current="{{ $code === $locale ? 'true' : 'false' }}">
                            {{ $languages->nativeName($code) }}
                        </a>
                    @endforeach
                </nav>
            @endif
        </header>
    @endif

    @if ($presentation->hasSection('categories') && $menu['categories']->isNotEmpty())
        <section>
            <h2>{{ __('Categories') }}</h2>
            <div class="cats">
                @foreach ($menu['categories'] as $category)
                    <a class="cat" href="#category-{{ $category->uuid }}">
                        <strong>{{ $category->name?->get($locale) }}</strong>
                        @if ($category->description)
                            <span class="desc">{{ $category->description->get($locale) }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($presentation->hasSection('all_services') || $presentation->hasSection('featured_services'))
        <section>
            <h2>{{ __('Services') }}</h2>

            @php
                $grouped = $presentation->sectionConfig('all_services')['group_by'] ?? 'category';
            @endphp

            @if ($grouped === 'category' && $menu['categories']->isNotEmpty())
                @foreach ($menu['categories'] as $category)
                    @php $inCategory = $menu['services']->where('service_category_id', $category->id); @endphp
                    @continue($inCategory->isEmpty())

                    <h3 id="category-{{ $category->uuid }}">{{ $category->name?->get($locale) }}</h3>
                    <div class="services">
                        @foreach ($inCategory as $service)
                            @include('menu.partials.service', ['service' => $service])
                        @endforeach
                    </div>
                @endforeach

                @php $uncategorised = $menu['services']->whereNull('service_category_id'); @endphp
                @if ($uncategorised->isNotEmpty())
                    <h3>{{ __('More') }}</h3>
                    <div class="services">
                        @foreach ($uncategorised as $service)
                            @include('menu.partials.service', ['service' => $service])
                        @endforeach
                    </div>
                @endif
            @else
                <div class="services">
                    @foreach ($menu['services'] as $service)
                        @include('menu.partials.service', ['service' => $service])
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    @if ($presentation->hasSection('employees') && $menu['employees']->isNotEmpty())
        <section>
            <h2>{{ __('Our team') }}</h2>
            {{-- Names only. Nothing else about staff is loaded at all. --}}
            <div class="people">
                @foreach ($menu['employees'] as $employee)
                    <span>{{ $employee->name?->get($locale) }}</span>
                @endforeach
            </div>
        </section>
    @endif

    @if ($presentation->hasSection('location') && $menu['branch'])
        <section>
            <h2>{{ __('Find us') }}</h2>
            @if ($menu['branch']->address)
                <p>{{ $menu['branch']->address->get($locale) }}</p>
            @endif

            @if ($menu['branch']->workingHours->isNotEmpty())
                <table class="hours">
                    @foreach ($menu['branch']->workingHours->groupBy('day_of_week') as $day => $intervals)
                        <tr>
                            <td>{{ __(\Carbon\Carbon::create(2024, 1, 7 + (int) $day)->format('l')) }}</td>
                            <td>
                                @foreach ($intervals as $interval)
                                    {{ \Illuminate\Support\Str::substr($interval->opens_at, 0, 5) }}–{{ \Illuminate\Support\Str::substr($interval->closes_at, 0, 5) }}@if (! $loop->last), @endif
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @if ($menu['branch']->map_url && ($presentation->sectionConfig('location')['show_map'] ?? true))
                <p><a href="{{ $menu['branch']->map_url }}" target="_blank" rel="noopener nofollow">{{ __('Open in maps') }}</a></p>
            @endif
        </section>
    @endif

    @if ($presentation->hasSection('contact') && $menu['branch'])
        <footer>
            @if ($menu['branch']->phone)
                <p><a href="tel:{{ $menu['branch']->phone }}">{{ $menu['branch']->phone }}</a></p>
            @endif
            @if ($menu['branch']->whatsapp && ($presentation->sectionConfig('contact')['show_whatsapp'] ?? true))
                <p>
                    <a href="https://wa.me/{{ preg_replace('/\D/', '', $menu['branch']->whatsapp) }}"
                       target="_blank" rel="noopener nofollow">{{ __('Message us on WhatsApp') }}</a>
                </p>
            @endif
            @if ($menu['branch']->email)
                <p><a href="mailto:{{ $menu['branch']->email }}">{{ $menu['branch']->email }}</a></p>
            @endif

            {{-- Pre-fills the center key on the account form. The form's
                 submitted key is what actually resolves the center, never this
                 query string (ADR-036). --}}
            <p><a href="{{ route('customer.signin', ['center' => $center->publicKey]) }}">{{ __('Your account') }}</a></p>
        </footer>
    @endif

</div>
</body>
</html>
