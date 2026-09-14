{{--
    An A4 invoice, for a customer who needs one on paper or as a browser-saved
    PDF. docs/18-SALES.md §§24–25: no PDF library — the browser lays out Arabic
    and Kurdish correctly, which the PHP PDF engines do not reliably do.
--}}
<!DOCTYPE html>
<html lang="{{ $invoice['locale'] }}" dir="{{ $invoice['direction'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice['number'] }}</title>

    <style>
        @page { size: A4; margin: 18mm; }

        body {
            font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Arabic", sans-serif;
            margin: 0 auto;
            max-inline-size: 180mm;
            color: #111;
            background: #fff;
            font-size: 11pt;
        }

        .identity { margin-block-end: 8mm; }
        .center { font-size: 20pt; font-weight: 700; }
        .branch { font-size: 13pt; font-weight: 600; }
        .meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 2mm 8mm; margin: 0 0 6mm; }
        .meta div, .totals div { display: flex; justify-content: space-between; gap: 4mm; }
        .meta dt, .totals dt { font-weight: 600; }
        .meta dd, .totals dd { margin: 0; }
        .lines { inline-size: 100%; border-collapse: collapse; margin-block-end: 6mm; }
        .lines th { text-align: start; border-block-end: 2px solid #111; padding: 2mm; }
        .lines td { border-block-end: 1px solid #ccc; padding: 2mm; vertical-align: top; }
        .lines .num { text-align: end; white-space: nowrap; }
        .addon, .variation { color: #444; font-size: 10pt; }
        .totals { margin-inline-start: auto; max-inline-size: 80mm; }
        .grand { font-size: 14pt; font-weight: 700; border-block-start: 2px solid #111; padding-block-start: 2mm; }
        .void-banner { border: 3px solid #b00; color: #b00; text-align: center; padding: 3mm; margin-block-end: 6mm; font-size: 16pt; }
        .thanks { margin-block-start: 10mm; color: #444; }

        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    @include('sales.partials.invoice-body', ['invoice' => $invoice])

    <p class="no-print">
        <button type="button" onclick="window.print()">{{ __('invoice_public.print') }}</button>
    </p>
</body>
</html>
