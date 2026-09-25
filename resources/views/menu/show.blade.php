<!DOCTYPE html>
{{--
    The public electronic menu.

    Mobile-first: customers open this from a phone, usually from a QR code on a
    table. Direction comes from the language registry, never a hardcoded list of
    RTL locales (docs/07-LOCALIZATION.md §10).

    Sections are drawn in the order the owner arranged them, each from its own
    partial in menu/sections. Every value in the stylesheet below comes from
    MenuStyle: validated hex colours and code-owned enum maps — a center cannot
    put arbitrary CSS here (ADR-036 / Phase 4 §14).

    The Manager's draft preview renders this same template with `$preview`, so
    what an owner checks is what a customer will get.
--}}
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ ($preview ?? false) ? 'noindex, nofollow' : 'index, follow' }}">
    <title>{{ $center->name }}</title>
    @if (($menu['brand']['favicon_url'] ?? null) !== null)
        <link rel="icon" href="{{ $menu['brand']['favicon_url'] }}">
    @endif
    <style>
        :root {
            @foreach ($menu['style']['vars'] as $property => $value)
            {{ $property }}: {{ $value }};
            @endforeach
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--fg);
            font: var(--base-size)/1.6 var(--font);
            -webkit-text-size-adjust: 100%;
        }

        a { color: var(--accent); }
        img { max-width: 100%; }

        .wrap { max-width: 46rem; margin-inline: auto; padding: 1.25rem 1rem 4rem; }
        .layout-grid .wrap { max-width: 56rem; }

        .preview-banner {
            position: sticky; top: 0; z-index: 5; margin: 0; padding: .5rem 1rem; text-align: center;
            background: #10131a; color: #fff; font: 600 .8rem/1.4 system-ui, sans-serif;
        }

        header.hero {
            padding: 2rem 0 1.25rem; border-bottom: 1px solid var(--line);
            color: var(--hero-fg);
        }
        .hero-tint header.hero, .hero-gradient header.hero {
            background: var(--hero-bg); border: 0; border-radius: var(--radius);
            padding: 2rem 1.25rem 1.5rem; margin-top: .5rem;
        }
        header.hero .brand { display: flex; align-items: center; gap: .9rem; }
        header.hero .logo { display: block; max-height: 3.5rem; width: auto; max-width: 11rem; object-fit: contain; }
        header.hero h1 { margin: 0; font-size: 1.75em; line-height: 1.2; letter-spacing: -.01em; color: var(--hero-title); }
        header.hero p.tagline { margin: .35rem 0 0; opacity: .8; }

        nav.langs { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
        nav.langs a {
            font-size: .875em; text-decoration: none; color: inherit; opacity: .85;
            border: 1px solid currentColor; border-radius: var(--radius); padding: .25rem .7rem;
        }
        nav.langs a[aria-current="true"] { opacity: 1; color: var(--on-accent); background: var(--accent); border-color: var(--accent); }

        details.langs { margin-top: 1rem; display: inline-block; position: relative; }
        details.langs summary {
            cursor: pointer; list-style: none; font-size: .875em; border: 1px solid currentColor;
            border-radius: var(--radius); padding: .3rem .8rem;
        }
        details.langs summary::-webkit-details-marker { display: none; }
        details.langs ul {
            position: absolute; inset-inline-start: 0; z-index: 3; margin: .3rem 0 0; padding: .3rem; list-style: none;
            min-width: 10rem; background: var(--bg); color: var(--fg); border: 1px solid var(--line); border-radius: .6rem;
            box-shadow: 0 8px 24px rgb(0 0 0 / 16%);
        }
        details.langs a { display: block; padding: .4rem .6rem; color: inherit; text-decoration: none; border-radius: .4rem; }
        details.langs a[aria-current="true"] { background: var(--surface); font-weight: 600; }

        section { margin-top: 2.25rem; }
        section > h2 { font-size: 1.15em; margin: 0 0 var(--gap); color: var(--primary); }
        .theme-dark section > h2 { color: var(--fg); }
        h3.group { font-size: 1em; margin: 1.5rem 0 .6rem; color: var(--muted); text-transform: none; }

        .cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: var(--gap); }
        .cats.list { grid-template-columns: 1fr; }
        .cats.chips { display: flex; flex-wrap: nowrap; overflow-x: auto; gap: .5rem; padding-bottom: .25rem; scrollbar-width: thin; }
        .cat {
            border: 1px solid var(--line); border-radius: var(--radius);
            padding: .85rem 1rem; background: var(--surface); text-decoration: none; color: inherit;
        }
        .cats.chips .cat { flex: 0 0 auto; padding: .4rem .9rem; white-space: nowrap; }
        .cats.list .cat { display: flex; gap: .9rem; align-items: center; }
        .cat strong { display: block; }
        .cat img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: calc(var(--radius) * .6); margin-bottom: .5rem; }
        .cats.list .cat img { width: 3.5rem; margin: 0; aspect-ratio: 1; }
        .cats.chips .cat img, .cats.chips .desc { display: none; }

        .services { display: grid; gap: var(--gap); }

        .service {
            border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface);
            padding: var(--gap); display: grid; gap: .5rem; align-content: start;
        }
        .cards-elevated .service, .cards-elevated .cat { box-shadow: 0 1px 3px rgb(0 0 0 / 12%); border-color: transparent; }
        .cards-flat .service, .cards-flat .cat { border-color: transparent; }

        .service h3 { margin: 0; font-size: 1.05em; }
        .service .meta { display: flex; justify-content: space-between; gap: 1rem; align-items: baseline; }
        .service .price { font-weight: 600; color: var(--primary); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .theme-dark .service .price { color: var(--fg); }
        .price-badge .service .price {
            background: var(--accent); color: var(--on-accent); border-radius: 999px; padding: .1rem .6rem; font-size: .9em;
        }
        .price-below .service .meta { flex-direction: column; gap: .15rem; }
        .service .desc { color: var(--muted); font-size: .925em; margin: 0; }

        .service img { width: 100%; height: auto; border-radius: var(--radius); display: block; }
        .ratio-landscape .service img { aspect-ratio: 16 / 9; object-fit: cover; }
        .ratio-square .service img { aspect-ratio: 1; object-fit: cover; }
        .ratio-portrait .service img { aspect-ratio: 4 / 5; object-fit: cover; }
        .ratio-hidden .service img { display: none; }

        .book {
            display: inline-block; margin-top: .25rem; padding: .5rem 1rem; border-radius: var(--radius);
            background: var(--accent); color: var(--on-accent); text-decoration: none; font-size: .9em; font-weight: 600;
            border: 2px solid var(--accent);
        }
        .cta-outline .book { background: transparent; color: var(--accent); }
        .cta-pill .book { border-radius: 999px; }

        ul.variants { list-style: none; margin: .25rem 0 0; padding: 0; display: grid; gap: .3rem; }
        ul.variants li { display: flex; justify-content: space-between; gap: 1rem; font-size: .925em; color: var(--muted); }

        .addons { font-size: .875em; color: var(--muted); margin: 0; }

        .layout-compact .service { grid-template-columns: minmax(0, 1fr) auto; padding: .7rem var(--gap); gap: .25rem 1rem; }
        .layout-compact .service > * { grid-column: 1; }
        .layout-compact .service > .actions { grid-column: 2; grid-row: 1 / span 3; align-self: center; }
        .layout-compact .service img, .layout-compact .service ul.variants { display: none; }

        .people { display: flex; flex-wrap: wrap; gap: .5rem; }
        .people span { border: 1px solid var(--line); border-radius: var(--radius); padding: .3rem .8rem; font-size: .9em; }

        .contact-links { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; margin: 0; padding: 0; list-style: none; }

        footer { margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--line); color: var(--muted); font-size: .925em; }

        table.hours { border-collapse: collapse; font-size: .925em; }
        table.hours td { padding-block: .15rem; padding-inline: 0 .75rem; }

        .empty { color: var(--muted); }

        @media (min-width: 40rem) {
            .layout-grid .services { grid-template-columns: repeat(2, 1fr); }
        }
        @media (min-width: 60rem) {
            .layout-grid .services { grid-template-columns: repeat(3, 1fr); }
        }
    </style>
</head>
<body class="{{ implode(' ', $menu['style']['classes']) }}">
@if ($preview ?? false)
    <p class="preview-banner" role="note">{{ __('menu_public.preview_banner') }}</p>
@endif
<div class="wrap">
    @forelse ($menu['presentation']->visibleSections() as $section)
        @include('menu.sections.'.$section['key'], ['config' => $section['config']])
    @empty
        <p class="empty">{{ __('menu_public.empty') }}</p>
    @endforelse
</div>
</body>
</html>
