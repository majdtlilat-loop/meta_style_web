{{--
    An 80mm queue ticket.

    docs/17-QUEUE.md §17.

    NO CUSTOMER PII. A queue ticket is left on tables, dropped on floors and
    handed to whoever is next: it carries a number, a place and a time. The
    service name is optional and is the only thing here that says anything about
    why the customer came.

    Direction comes from the language registry, so Arabic and Kurdish print
    right-to-left through the same markup (docs/07-LOCALIZATION.md §10).
--}}
<!DOCTYPE html>
<html lang="{{ $ticket['locale'] }}" dir="{{ $ticket['direction'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ticket['number'] }}</title>

    <style>
        /* Thermal paper: 80mm wide, as long as it needs to be. */
        @page {
            size: 80mm auto;
            margin: 4mm;
        }

        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 0;
            padding: 0;
            /* Thermal printers are monochrome; anything subtle disappears. */
            color: #000;
            background: #fff;
            inline-size: 72mm;
        }

        .ticket {
            text-align: center;
            padding-block: 4mm;
        }

        .branch {
            font-size: 11pt;
            font-weight: 600;
            margin-block-end: 2mm;
        }

        .number {
            /* The one thing that has to be readable at arm's length. */
            font-size: 46pt;
            font-weight: 700;
            line-height: 1.1;
            margin-block: 3mm;
            letter-spacing: 0.05em;
        }

        .destination {
            font-size: 16pt;
            font-weight: 600;
            margin-block-end: 2mm;
        }

        .meta {
            font-size: 10pt;
            margin-block-start: 3mm;
            border-block-start: 1px dashed #000;
            padding-block-start: 2mm;
        }

        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="ticket">
        @if ($ticket['branch_name'] !== null)
            <div class="branch">{{ $ticket['branch_name'] }}</div>
        @endif

        <div class="number">{{ $ticket['number'] }}</div>

        @if ($ticket['destination_code'] !== null)
            <div class="destination">
                {{ __('queue_public.destination') }}: {{ $ticket['destination_code'] }}
                @if ($ticket['destination_name'] !== null)
                    <br>{{ $ticket['destination_name'] }}
                @endif
            </div>
        @elseif ($ticket['department_name'] !== null)
            <div class="destination">{{ $ticket['department_name'] }}</div>
        @endif

        @if ($ticket['service_name'] !== null)
            <div>{{ $ticket['service_name'] }}</div>
        @endif

        <div class="meta">
            {{ __('queue_public.issued_at') }}: {{ $ticket['issued_at'] }}<br>
            {{ $ticket['issued_date'] }}
        </div>

        <div class="meta">{{ __('queue_public.thank_you') }}</div>
    </div>

    <p class="no-print">
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    </p>

    <script>
        // Requested, not guaranteed: browsers outside kiosk mode may refuse to
        // print without a gesture, which is why the button above exists.
        window.addEventListener('load', function () {
            try { window.print(); } catch (e) { /* the desk presses the button */ }
        });
    </script>
</body>
</html>
