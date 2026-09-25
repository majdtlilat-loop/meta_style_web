{{--
    The customer's digital invoice.

    docs/18-SALES.md §46. Responsive, read-only, printable, no login, no
    controls beyond the browser's own print. Reached only by an opaque share
    token, and nothing on this page links anywhere internal.
--}}
<!DOCTYPE html>
<html lang="{{ $invoice['locale'] }}" dir="{{ $invoice['direction'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A customer's invoice has no business in a search index, and the token
         must not leak to anything this page might link to. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('invoice_public.invoice') }} {{ $invoice['number'] }}</title>

    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Arabic", sans-serif;
            margin: 0;
            background: #f4f4f2;
            color: #111;
        }

        .sheet {
            background: #fff;
            max-inline-size: 42rem;
            margin: 1rem auto;
            padding: 1.25rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .identity { margin-block-end: 1rem; }
        .center { font-size: 1.35rem; font-weight: 700; }
        .branch { font-weight: 600; }
        .address, .phone { color: #555; font-size: 0.9rem; }
        .meta { margin: 0 0 1rem; display: grid; gap: 0.25rem; }
        .meta div, .totals div { display: flex; justify-content: space-between; gap: 1rem; }
        .meta dt, .totals dt { color: #555; }
        .meta dd, .totals dd { margin: 0; font-weight: 600; }
        .lines { inline-size: 100%; border-collapse: collapse; margin-block-end: 1rem; font-size: 0.95rem; }
        .lines th { text-align: start; color: #555; font-weight: 600; border-block-end: 1px solid #ddd; padding: 0.4rem 0.25rem; }
        .lines td { border-block-end: 1px solid #eee; padding: 0.5rem 0.25rem; vertical-align: top; }
        .lines .num { text-align: end; white-space: nowrap; }
        .addon, .variation { color: #666; font-size: 0.85rem; }
        .totals { margin: 0; }
        .grand { font-size: 1.2rem; border-block-start: 1px solid #ddd; padding-block-start: 0.5rem; margin-block-start: 0.25rem; }
        .void-banner { background: #fde8e8; color: #9b1c1c; border-radius: 0.375rem; padding: 0.75rem; margin-block-end: 1rem; text-align: center; }
        .thanks { text-align: center; color: #666; margin-block-start: 1.25rem; }
        .actions { text-align: center; margin-block-end: 1rem; }

        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; max-inline-size: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <main class="sheet">
        @include('sales.partials.invoice-body', ['invoice' => $invoice])

        {{-- Paid, pending, remaining — and paying online where the center offers
             it. Allow-listed by PublicInvoicePayments; the invoice above renders
             whether or not online payment is available (docs/19-PAYMENTS.md §60). --}}
        @isset($payment)
            <section class="payment">
                <dl class="totals">
                    <div><dt>{{ __('invoice_public.paid') }}</dt><dd>{{ $payment['paid']['formatted'] }}</dd></div>
                    @if ($payment['pending']['amount'] > 0)
                        <div><dt>{{ __('invoice_public.payment_pending') }}</dt><dd>{{ $payment['pending']['formatted'] }}</dd></div>
                    @endif
                    @unless ($payment['voided'])
                        <div class="grand"><dt>{{ __('invoice_public.remaining') }}</dt><dd>{{ $payment['remaining']['formatted'] }}</dd></div>
                    @endunless
                </dl>

                @if (session('payment_status') === 'unavailable')
                    <p class="notice" role="alert">{{ __('invoice_public.payment_unavailable') }}</p>
                @endif

                @foreach ($payment['pending_online'] as $pending)
                    <div class="pending-online" role="status">
                        <p>{{ __('invoice_public.payment_waiting') }}</p>
                        @if ($pending['code'] !== null)
                            <p>{{ __('invoice_public.payment_code') }}: <strong dir="ltr">{{ $pending['code'] }}</strong></p>
                        @endif
                        @if ($pending['link'] !== null)
                            <p><a href="{{ $pending['link'] }}" rel="noopener noreferrer">{{ __('invoice_public.open_payment_app') }}</a></p>
                        @endif
                    </div>
                @endforeach

                @foreach ($payment['online_options'] as $option)
                    <form method="POST" action="{{ route('invoice.public.pay', ['center' => request()->route('center'), 'token' => request()->route('token')]) }}" class="actions">
                        @csrf
                        <input type="hidden" name="gateway" value="{{ $option['gateway'] }}">
                        <input type="hidden" name="payment_key" value="{{ $paymentKey }}">
                        <button type="submit">{{ __('invoice_public.pay_with', ['provider' => $option['name']]) }}</button>
                    </form>
                @endforeach
            </section>
        @endisset
    </main>

    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('invoice_public.print') }}</button>
    </div>
</body>
</html>
