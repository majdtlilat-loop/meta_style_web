<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentSource;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Domain\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * What the customer's invoice page may show and do about payment.
 *
 * ## An allow-list
 *
 *   paid, pending, remaining, state, voided        — amounts, already formatted
 *   online options: an opaque gateway key + a provider's display name
 *   a pending online payment: its code, the provider's link, when it expires
 *
 * Never: a payment uuid, a cashier, an internal id, a failure message, a manual
 * transfer's label or reference, a refund reason, anything from the ledger or
 * the audit log (docs/19-PAYMENTS.md §§58, 74). A test compares the key list.
 *
 * ## When online payment is offered
 *
 * `payments` is on, the sale is not voided, something is left to collect, and
 * the invoice's own branch has an enabled, configured account at a provider
 * with a verified adapter. Otherwise the invoice still renders — only the pay
 * button is absent (§60).
 */
final class PublicInvoicePayments
{
    public function __construct(
        private readonly PaymentsAccess $access,
        private readonly InvoiceSettlement $settlement,
        private readonly PaymentProviderRegistry $providers,
        private readonly UsableGateways $gateways,
    ) {}

    /**
     * @return array{state: string, voided: bool, paid: array<string, mixed>, pending: array<string, mixed>, remaining: array<string, mixed>, online_options: list<array{gateway: string, name: string}>, pending_online: list<array{code: string|null, link: string|null, expires_at: string|null}>}
     */
    public function forInvoice(Invoice $invoice): array
    {
        $summary = $this->settlement->forInvoice($invoice);
        $locale = app()->getLocale();
        $currency = Currency::tryFrom($invoice->currency) ?? Currency::default();

        $options = [];

        if ($this->access->onlinePaymentsEnabled() && ! $summary->voided && $summary->availableCollectibleMinor > 0) {
            foreach ($this->gateways->forInvoice($invoice) as $account) {
                $options[] = [
                    'gateway' => $account->uuid,
                    'name' => $this->providers->get($account->provider)->displayName(),
                ];
            }
        }

        /** @var list<Payment> $pending */
        $pending = Payment::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('status', PaymentStatus::Pending->value)
            ->where('method', PaymentMethod::Gateway->value)
            ->where('source', PaymentSource::PublicLink->value)
            ->whereNotNull('provider_payment_reference')
            ->orderByDesc('initiated_at')
            ->limit(3)
            ->get()
            ->all();

        return [
            'state' => $summary->state->value,
            'voided' => $summary->voided,
            'paid' => Money::fromMinor($summary->succeededMinor, $currency)->toArray($locale),
            'pending' => Money::fromMinor($summary->pendingMinor, $currency)->toArray($locale),
            'remaining' => Money::fromMinor($summary->availableCollectibleMinor, $currency)->toArray($locale),
            'online_options' => $options,
            'pending_online' => array_map(fn (Payment $payment): array => $this->presentPending($payment), $pending),
        ];
    }

    /**
     * Starts an online payment for everything that is left, through one of the
     * branch's own gateways.
     *
     * @throws PaymentFailed
     */
    public function start(Invoice $invoice, string $gatewayUuid, string $idempotencyToken, InitiateGatewayPayment $initiate, ?CarbonImmutable $now = null): Payment
    {
        /** @var GatewayAccount|null $account */
        $account = GatewayAccount::query()
            ->where('uuid', $gatewayUuid)
            ->where('branch_id', $invoice->branch_id)
            ->first();

        if (! $account instanceof GatewayAccount) {
            throw PaymentFailed::policy('That payment option is not available.');
        }

        // A repeat of the same press returns the payment it already started,
        // even when nothing is left to collect because THAT payment reserved it.
        $existing = Payment::query()->where('idempotency_token', $idempotencyToken)->first();

        if ($existing instanceof Payment && $existing->invoice_id === $invoice->getKey()) {
            return $existing;
        }

        $remaining = $this->settlement->forInvoice($invoice)->availableCollectibleMinor;

        return $initiate->fromPublicLink($invoice, $account, $remaining, $idempotencyToken, $now);
    }

    /**
     * @return array{code: string|null, link: string|null, expires_at: string|null}
     */
    public function presentPending(Payment $payment): array
    {
        return [
            'code' => $payment->provider_display_code,
            'link' => $payment->status === PaymentStatus::Pending ? $payment->checkout_url : null,
            'expires_at' => $payment->expires_at?->toIso8601String(),
        ];
    }
}
