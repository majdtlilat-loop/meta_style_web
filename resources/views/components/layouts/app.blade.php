@php
    /** @var \App\Kernel\Localization\LanguageRegistry $languages */
    $languages = app(\App\Kernel\Localization\LanguageRegistry::class);
    $locale = app()->getLocale();
    $direction = $languages->direction($locale);
@endphp
<!DOCTYPE html>
{{--
    Direction comes from the language registry, never a hardcoded list of RTL
    locales (docs/07-LOCALIZATION.md §6, §10). Every future layout inherits
    this; do not reintroduce an inline `in_array($locale, ['ar', ...])`.
--}}
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --fg: #10131a; --muted: #5b6472; --bg: #fbfbfd; --line: #e3e6ec; --accent: #7a5cff; }
        @media (prefers-color-scheme: dark) {
            :root { --fg: #e8eaf0; --muted: #949cab; --bg: #0d0f14; --line: #232833; --accent: #a894ff; }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 3rem 1.5rem; background: var(--bg); color: var(--fg);
            font: 16px/1.6 ui-sans-serif, system-ui, "Segoe UI", Tahoma, "Noto Sans Arabic", sans-serif;
        }
        main { max-width: 46rem; margin-inline: auto; }
        h1 { font-size: 1.5rem; margin: 0 0 .25rem; letter-spacing: -.01em; }
        .sub { color: var(--muted); margin: 0 0 2rem; font-size: .95rem; }
        .card { border: 1px solid var(--line); border-radius: .75rem; padding: 1.25rem 1.5rem; background: color-mix(in srgb, var(--bg) 80%, transparent); }
        dl { display: grid; grid-template-columns: minmax(8rem, auto) 1fr; gap: .5rem 1.5rem; margin: 0; }
        dt { color: var(--muted); font-size: .875rem; }
        dd { margin: 0; font-variant-numeric: tabular-nums; }
        button { font: inherit; color: var(--accent); background: none; border: 0; padding: 0; cursor: pointer; text-decoration: underline; text-underline-offset: 3px; }
        code { font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: .875em; overflow-wrap: anywhere; }
        a { color: var(--accent); }
        table.list { width: 100%; border-collapse: collapse; font-size: .925rem; }
        table.list th { text-align: start; font-weight: 600; font-size: .8rem; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; padding-bottom: .5rem; }
        table.list td { padding: .55rem 0; border-top: 1px solid var(--line); vertical-align: top; }
        table.list td.actions { text-align: end; white-space: nowrap; }
        table.list td.actions button { margin-inline-start: .75rem; }
        tr.muted-row { opacity: .55; }
        .tag { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; border: 1px solid var(--line); border-radius: 1rem; padding: .05rem .5rem; color: var(--muted); margin-inline-start: .4rem; }
        .notice { background: color-mix(in srgb, var(--accent) 12%, transparent); border: 1px solid var(--line); border-radius: .5rem; padding: .6rem .9rem; }
        .error { color: #c0392b; font-size: .85rem; margin: .25rem 0 .75rem; }
        .row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-bottom: .5rem; }
        .row input, .row select { width: auto; flex: 1 1 8rem; }
        .row.section-row { border-top: 1px solid var(--line); padding-top: .6rem; }
        .two-up { display: grid; gap: 1rem; grid-template-columns: 1fr; }
        @media (min-width: 34rem) { .two-up { grid-template-columns: 1fr 1fr; } }
        label.inline { display: flex; align-items: center; gap: .45rem; color: var(--fg); font-size: .95rem; margin-bottom: .4rem; }
        label.inline input { width: auto; }
        h2 { font-size: 1.15rem; margin: 2rem 0 .75rem; }
        h3 { font-size: 1rem; margin: 1.25rem 0 .5rem; }

        label { display: block; font-size: .875rem; color: var(--muted); margin-bottom: .35rem; }
        input[type=text], input[type=email], input[type=password], select {
            width: 100%; padding: .6rem .75rem; border: 1px solid var(--line); border-radius: .5rem;
            background: var(--bg); color: var(--fg); font: inherit;
        }
        .field { margin-bottom: 1rem; }
        .error { color: #d33; font-size: .8125rem; margin-top: .35rem; }
        .btn {
            display: inline-block; padding: .6rem 1.1rem; border-radius: .5rem; border: 0;
            background: var(--accent); color: #fff; font: inherit; cursor: pointer; text-decoration: none;
        }
        .btn.secondary { background: transparent; color: var(--accent); border: 1px solid var(--line); }
        table { width: 100%; border-collapse: collapse; font-size: .9375rem; }
        th, td { text-align: start; padding: .55rem .5rem; border-bottom: 1px solid var(--line); }
        th { color: var(--muted); font-weight: 600; font-size: .8125rem; }
        nav.center-nav { display: flex; gap: 1rem; margin-bottom: 2rem; align-items: center; flex-wrap: wrap; }
        .pill { display: inline-block; padding: .15rem .5rem; border: 1px solid var(--line); border-radius: 999px; font-size: .75rem; color: var(--muted); }
        .stack > * + * { margin-top: 1.5rem; }
        fieldset { border: 1px solid var(--line); border-radius: .5rem; padding: 1rem; }
        legend { font-size: .8125rem; color: var(--muted); padding-inline: .35rem; }
        .checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); gap: .4rem; }
        .checks label { display: flex; gap: .5rem; align-items: center; color: var(--fg); font-size: .875rem; margin: 0; }
    </style>
    @livewireStyles
</head>
<body>
    <main>
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
