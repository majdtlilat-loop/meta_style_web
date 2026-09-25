{{--
    The frame shared by every SaaS billing document. One HTML for both the
    print view and the PDF (mPDF), so the CSS stays within what mPDF renders:
    tables, borders, padding, colours — no flex, grid or CSS variables. Always
    light: a printed invoice ignores the administrator's dark theme.
--}}
@php
    $accent = $doc['accent'];
    $compact = $doc['layout'] === 'compact';
@endphp
<!doctype html>
{{-- No lang attribute in the PDF: mPDF would force the language's font onto Latin runs too. It detects each script run itself. --}}
<html @unless($doc['pdf']) lang="{{ $doc['lang'] }}" @endunless dir="{{ $doc['dir'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $doc['title'] }}</title>
    <style>
        @unless($doc['pdf'])
        @font-face { font-family: 'Poppins'; src: url('{{ \Illuminate\Support\Facades\Vite::asset('resources/fonts/Poppins-Regular.ttf') }}') format('truetype'); font-weight: 100 500; }
        @font-face { font-family: 'Poppins'; src: url('{{ \Illuminate\Support\Facades\Vite::asset('resources/fonts/Poppins-SemiBold.ttf') }}') format('truetype'); font-weight: 600 900; }
        @font-face { font-family: 'Noto Sans Arabic'; src: url('{{ \Illuminate\Support\Facades\Vite::asset('resources/fonts/NotoSansArabic-Light.ttf') }}') format('truetype'); font-weight: 100 400; }
        @font-face { font-family: 'Noto Sans Arabic'; src: url('{{ \Illuminate\Support\Facades\Vite::asset('resources/fonts/NotoSansArabic-Medium.ttf') }}') format('truetype'); font-weight: 500 900; }
        @endunless
        body { margin: 0; background: #ffffff; color: #2e2119; font-family: poppins, 'Noto Sans Arabic', Arial, sans-serif; font-size: {{ $compact ? '9pt' : '10pt' }}; line-height: 1.45; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .muted { color: #6e5a4e; }
        .subtle { color: #857063; font-size: 8.5pt; }
        .strong { font-weight: bold; }
        .num { white-space: nowrap; }
        .end { text-align: {{ $doc['dir'] === 'rtl' ? 'left' : 'right' }}; }
        .start { text-align: {{ $doc['dir'] === 'rtl' ? 'right' : 'left' }}; }
        .center { text-align: center; }
        .band { background: {{ $accent }}; color: #ffffff; }
        .band td { padding: {{ $compact ? '10px 14px' : '16px 18px' }}; }
        .rule { border-top: 3px solid {{ $accent }}; }
        .title { font-size: {{ $compact ? '15pt' : '20pt' }}; font-weight: bold; letter-spacing: {{ $doc['dir'] === 'rtl' ? '0' : '0.5px' }}; color: {{ $doc['layout'] === 'modern' ? '#ffffff' : $accent }}; }
        .company { font-weight: bold; font-size: 11pt; }
        .box { border: 1px solid #e6d7cd; }
        .box td { padding: {{ $compact ? '6px 10px' : '9px 12px' }}; }
        .label { color: #857063; font-size: 8pt; text-transform: {{ $doc['dir'] === 'rtl' ? 'none' : 'uppercase' }}; letter-spacing: {{ $doc['dir'] === 'rtl' ? '0' : '0.4px' }}; }
        .lines th { background: #f7efe9; color: #6e5a4e; font-size: 8pt; font-weight: bold; padding: {{ $compact ? '5px 8px' : '8px 10px' }}; border-bottom: 1px solid #e6d7cd; text-transform: {{ $doc['dir'] === 'rtl' ? 'none' : 'uppercase' }}; }
        .lines td { padding: {{ $compact ? '5px 8px' : '8px 10px' }}; border-bottom: 1px solid #f0e4dc; }
        .totals td { padding: {{ $compact ? '4px 8px' : '6px 10px' }}; }
        .totals .grand td { border-top: 2px solid {{ $accent }}; font-weight: bold; font-size: 11pt; }
        {{-- No border on inline badges in the PDF: mPDF's inline-border code path warns. --}}
        .badge { padding: 2px 8px; @unless($doc['pdf']) border: 1px solid {{ $accent }}; @endunless background: #f7efe9; color: {{ $accent }}; font-size: 8pt; font-weight: bold; }
        .band .badge { @unless($doc['pdf']) border-color: #ffffff; @endunless background: #ffffff; color: {{ $accent }}; }
        .void, .band .void { color: #b4334a; @unless($doc['pdf']) border-color: #b4334a; @endunless }
        .note { background: #fbf5f1; border: 1px solid #efe2d9; padding: 10px 12px; }
        .reversed { color: #857063; text-decoration: line-through; }
        .sample { background: #fdf2e0; border: 1px solid #f0d3a6; color: #955709; padding: 6px 10px; text-align: center; font-size: 8.5pt; }
        .spacer { height: {{ $compact ? '10px' : '16px' }}; }
        .footer { border-top: 1px solid #e6d7cd; color: #857063; font-size: 8pt; padding-top: 8px; }
        @unless($doc['pdf'])
        {{-- Print view only: mPDF takes paper and margins from PdfRenderer, and an @page size rule breaks its layout. --}}
        @page { size: {{ $doc['paper'] === 'Letter' ? 'letter' : 'A4' }}; margin: 14mm; }
        html { background: #efe7e2; }
        .sheet { max-width: {{ $doc['paper'] === 'Letter' ? '216mm' : '210mm' }}; margin: 24px auto; padding: 14mm; background: #ffffff; box-shadow: 0 10px 30px rgba(46, 33, 25, .15); box-sizing: border-box; }
        .toolbar { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: center; padding: 10px 16px; background: #2e2119; color: #fff; font-family: poppins, 'Noto Sans Arabic', Arial, sans-serif; font-size: 13px; }
        .toolbar a, .toolbar button { display: inline-block; padding: 7px 14px; border: 1px solid rgba(255,255,255,.35); border-radius: 8px; background: transparent; color: #fff; font: inherit; text-decoration: none; cursor: pointer; }
        .toolbar .primary { background: {{ $accent }}; border-color: {{ $accent }}; }
        .toolbar .current { background: rgba(255,255,255,.15); }
        .toolbar .gap { flex: 0 0 12px; }
        @media print {
            html, body { background: #ffffff; }
            .toolbar { display: none; }
            .sheet { margin: 0; padding: 0; max-width: none; box-shadow: none; }
        }
        @media (max-width: 640px) { .sheet { margin: 0; padding: 16px; } }
        @endunless
    </style>
</head>
<body>
@unless($doc['pdf'])
    @isset($toolbar)
        <nav class="toolbar" aria-label="{{ __('saas_documents.toolbar.label') }}">
            <button type="button" class="primary" onclick="window.print()">{{ __('saas_documents.toolbar.print') }}</button>
            <a href="{{ $toolbar['pdf'] }}">{{ __('saas_documents.toolbar.pdf') }}</a>
            <span class="gap"></span>
            @foreach($toolbar['languages'] as $code => $url)
                <a href="{{ $url }}" @class(['current' => $code === $doc['lang']]) lang="{{ $code }}">{{ $code === 'ckb' ? 'KU' : mb_strtoupper($code) }}</a>
            @endforeach
        </nav>
    @endisset
@endunless
<div class="sheet">
    @yield('document')
</div>
@if(! $doc['pdf'] && ($autoPrint ?? false))
    <script>window.addEventListener('load', () => window.print());</script>
@endif
</body>
</html>
