<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\PublicInvoicePayments;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Sales\Application\PublicInvoice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's digital invoice page — and, where the center offers it, paying
 * it online.
 *
 * Resolved from the center's public key (`public.tenant`) and the invoice's
 * 256-bit share secret. Nothing on the page links anywhere internal.
 *
 * The invoice always renders. Paid / pending / remaining are shown from the
 * server's settlement; a pay button appears only when online payments are
 * available for this invoice's branch — and its absence never hides the
 * invoice (docs/18-SALES.md §46, docs/19-PAYMENTS.md §60).
 */
final class PublicInvoicePageController extends Controller
{
    public function __invoke(string $center, string $token, PublicInvoice $invoices, PublicInvoicePayments $payments): View
    {
        unset($center);

        $document = $invoices->forToken($token);
        $invoice = $invoices->invoiceForToken($token);

        if ($document === null || $invoice === null) {
            throw new NotFoundHttpException;
        }

        return view('sales.invoice-public', [
            'invoice' => $document,
            'payment' => $payments->forInvoice($invoice),
            // A fresh key per page render: pressing "pay" twice sends the same one.
            'paymentKey' => (string) Str::uuid(),
        ]);
    }

    /**
     * Starts an online payment for the remaining balance and returns to the
     * invoice, which then shows the provider's code and link. The redirect back
     * proves nothing about payment — only the provider's verified answer settles
     * it (§30).
     */
    public function pay(Request $request, string $center, string $token, PublicInvoice $invoices, PublicInvoicePayments $payments, InitiateGatewayPayment $initiate): RedirectResponse
    {
        $validated = $request->validate([
            'gateway' => ['required', 'uuid'],
            'payment_key' => ['required', 'uuid'],
        ]);

        $invoice = $invoices->invoiceForToken($token) ?? throw new NotFoundHttpException;

        $back = redirect()->route('invoice.public', ['center' => $center, 'token' => $token]);

        try {
            $payments->start($invoice, (string) $validated['gateway'], (string) $validated['payment_key'], $initiate);
        } catch (PaymentFailed) {
            // The same message for every refusal (§84).
            return $back->with('payment_status', 'unavailable');
        }

        return $back->with('payment_status', 'started');
    }
}
