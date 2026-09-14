{{--
    An 80mm invoice — the receipt a customer takes from the counter.

    docs/18-SALES.md §§22, 24. A LAYOUT of the invoice, not a second record.
    Browser print; no ESC/POS, no print agent.
--}}
<!DOCTYPE html>
<html lang="{{ $invoice['locale'] }}" dir="{{ $invoice['direction'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice['number'] }}</title>

    <style>
        @page { size: 80mm auto; margin: 3mm; }

        body {
            font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Arabic", sans-serif;
            margin: 0;
            color: #000;
            background: #fff;
            inline-size: 74mm;
            font-size: 9pt;
        }

        .identity { text-align: center; margin-block-end: 2mm; }
        .center { font-size: 12pt; font-weight: 700; }
        .branch { font-weight: 600; }
        .meta, .totals { margin: 0; }
        .meta div, .totals div { display: flex; justify-content: space-between; gap: 2mm; }
        .meta dt, .totals dt { font-weight: 600; }
        .meta dd, .totals dd { margin: 0; }
        .lines { inline-size: 100%; border-collapse: collapse; margin-block: 2mm; }
        .lines th, .lines td { padding-block: 1mm; text-align: start; vertical-align: top; }
        .lines thead { border-block: 1px dashed #000; }
        .lines .num { text-align: end; white-space: nowrap; }
        .addon, .variation { font-size: 8pt; }
        .totals { border-block-start: 1px dashed #000; padding-block-start: 1mm; }
        .grand { font-size: 12pt; font-weight: 700; }
        .void-banner { border: 2px solid #000; text-align: center; padding: 1mm; margin-block-end: 2mm; font-size: 11pt; }
        .thanks { text-align: center; margin-block-start: 3mm; }

        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    @include('sales.partials.invoice-body', ['invoice' => $invoice])

    <p class="no-print" style="text-align:center">
        <button type="button" onclick="window.print()">{{ __('invoice_public.print') }}</button>
    </p>
</body>
</html>
