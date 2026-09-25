<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\PublicInvoicePayments;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Sales\Application\PublicInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A customer paying their invoice online, from the invoice link.
 *
 * The share secret in the URL is the whole authority: it resolves the invoice,
 * and nothing else is accepted from the client but WHICH of the branch's
 * gateways to use and an idempotency token. The amount is the invoice's
 * remaining balance, computed by the server.
 *
 * Errors are generic on purpose. A refusal never says whether another center or
 * payment exists, never echoes a provider's message (docs/19-PAYMENTS.md §§29, 84).
 */
final class PublicPaymentController extends Controller
{
    public function options(string $center, string $token, PublicInvoice $invoices, PublicInvoicePayments $payments): JsonResponse
    {
        unset($center);

        $invoice = $invoices->invoiceForToken($token) ?? throw new NotFoundHttpException;

        return ApiResponse::data(['payment' => $payments->forInvoice($invoice)]);
    }

    public function store(Request $request, string $center, string $token, PublicInvoice $invoices, PublicInvoicePayments $payments, InitiateGatewayPayment $initiate): JsonResponse
    {
        unset($center);

        $validated = $request->validate([
            'gateway' => ['required', 'uuid'],
            'idempotency_token' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        $invoice = $invoices->invoiceForToken($token) ?? throw new NotFoundHttpException;

        try {
            $payment = $payments->start($invoice, (string) $validated['gateway'], (string) $validated['idempotency_token'], $initiate);
        } catch (PaymentFailed) {
            // One answer for every refusal: nothing about why is the payer's
            // business, and a precise reason would help somebody probing.
            return ApiResponse::error(ApiErrorCode::PaymentsPolicyViolation, (string) __('invoice_public.payment_unavailable'), status: 422);
        }

        return ApiResponse::data(['payment' => $payments->presentPending($payment)], 201);
    }
}
