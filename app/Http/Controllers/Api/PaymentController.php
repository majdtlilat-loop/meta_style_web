<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Application\Actions\ResolveGatewayPayment;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Application\PaymentsPresenter;
use App\Modules\Payments\Application\PaymentsQuery;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff payments: an invoice's settlement, taking money, resolving an online
 * payment, refunds. Validate → one Action → present (docs/19-PAYMENTS.md §57).
 *
 * Every amount is integer minor units. Nothing here computes a balance: the
 * settlement comes from `InvoiceSettlement`, and the Actions decide what may be
 * collected or refunded under their locks.
 */
final class PaymentController extends Controller
{
    public function settlement(Request $request, string $invoiceUuid, PaymentsQuery $query, InvoiceSettlement $settlement, PaymentsPresenter $presenter): JsonResponse
    {
        $invoice = $query->invoice($invoiceUuid, $this->user($request));

        return ApiResponse::data([
            'invoice' => $invoice->uuid,
            'settlement' => $presenter->settlement($settlement->forInvoice($invoice)),
            'payments' => array_map(fn ($payment): array => $presenter->payment($payment), $query->forInvoice($invoice)),
        ]);
    }

    public function collect(
        Request $request,
        string $invoiceUuid,
        PaymentsQuery $query,
        CollectDeskPayment $desk,
        InitiateGatewayPayment $gateway,
        PaymentsPresenter $presenter,
    ): JsonResponse {
        $user = $this->user($request);

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in(array_map(static fn (PaymentMethod $m): string => $m->value, PaymentMethod::cases()))],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'manual_method_label' => ['nullable', 'string', 'max:60'],
            'manual_reference' => ['nullable', 'string', 'max:120'],
            'gateway_account' => ['nullable', 'required_if:method,gateway', 'uuid'],
            'idempotency_token' => ['nullable', 'string', 'max:64'],
        ]);

        $invoice = $query->invoice($invoiceUuid, $user);
        $method = PaymentMethod::from($validated['method']);

        $payment = $method === PaymentMethod::Gateway
            ? $gateway->fromDesk(
                $invoice,
                $query->gatewayAccount((string) $validated['gateway_account'], $user),
                $user,
                (int) $validated['amount_minor'],
                $validated['idempotency_token'] ?? null,
            )
            : $desk(
                $invoice,
                $user,
                $method,
                (int) $validated['amount_minor'],
                $validated['manual_method_label'] ?? null,
                $validated['manual_reference'] ?? null,
                $validated['idempotency_token'] ?? null,
            );

        return ApiResponse::data(['payment' => $presenter->payment($payment)], 201);
    }

    public function show(Request $request, string $uuid, PaymentsQuery $query, PaymentsPresenter $presenter): JsonResponse
    {
        return ApiResponse::data(['payment' => $presenter->payment($query->payment($uuid, $this->user($request)))]);
    }

    public function refresh(Request $request, string $uuid, PaymentsQuery $query, ResolveGatewayPayment $resolve, PaymentsPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $result = $resolve->refresh($query->payment($uuid, $user), $user);

        return ApiResponse::data(['result' => $result->value, 'payment' => $presenter->payment($query->payment($uuid, $user))]);
    }

    public function cancel(Request $request, string $uuid, PaymentsQuery $query, ResolveGatewayPayment $resolve, PaymentsPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $resolve->cancel($query->payment($uuid, $user), $user);

        return ApiResponse::data(['payment' => $presenter->payment($query->payment($uuid, $user))]);
    }

    public function refund(Request $request, string $uuid, PaymentsQuery $query, RequestRefund $refund, PaymentsPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in(array_map(static fn (PaymentMethod $m): string => $m->value, PaymentMethod::cases()))],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:190'],
            'idempotency_token' => ['nullable', 'string', 'max:64'],
        ]);

        $created = $refund(
            $query->payment($uuid, $user),
            $user,
            PaymentMethod::from($validated['method']),
            (int) $validated['amount_minor'],
            (string) $validated['reason'],
            $validated['idempotency_token'] ?? null,
        );

        return ApiResponse::data(['refund' => $presenter->refund($created)], 201);
    }

    public function showRefund(Request $request, string $uuid, PaymentsQuery $query, PaymentsPresenter $presenter): JsonResponse
    {
        return ApiResponse::data(['refund' => $presenter->refund($query->refund($uuid, $this->user($request)))]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
