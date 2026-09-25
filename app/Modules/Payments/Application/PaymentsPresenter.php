<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Payments\Domain\Data\InvoiceSettlementSummary;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;

/**
 * The STAFF view of payments, refunds, settlement and gateway accounts.
 * Allow-lists, field by field.
 *
 * A gateway account is presented as: provider, display name, environment,
 * enabled, configured, a masked non-secret hint, when and by whom. Its
 * credentials — encrypted or not — are never a key in any array this returns,
 * and an architecture test scans for it (docs/19-PAYMENTS.md §48).
 *
 * No numeric id, ever.
 */
final class PaymentsPresenter
{
    public function __construct(private readonly PaymentProviderRegistry $providers) {}

    /**
     * @return array<string, mixed>
     */
    public function settlement(InvoiceSettlementSummary $summary): array
    {
        $locale = app()->getLocale();
        $currency = Currency::tryFrom($summary->currency) ?? Currency::default();
        $money = static fn (int $minor): array => Money::fromMinor($minor, $currency)->toArray($locale);

        return [
            'state' => $summary->state->value,
            'voided' => $summary->voided,
            'invoice_total' => $money($summary->invoiceTotalMinor),
            'succeeded' => $money($summary->succeededMinor),
            'pending' => $money($summary->pendingMinor),
            'available_collectible' => $money($summary->availableCollectibleMinor),
            'refunded' => $money($summary->refundedMinor),
            'net_collected' => $money($summary->netCollectedMinor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(Payment $payment): array
    {
        $locale = app()->getLocale();

        return [
            'uuid' => $payment->uuid,
            'invoice' => $payment->relationLoaded('invoice') ? $payment->invoice?->uuid : null,
            'invoice_number' => $payment->relationLoaded('invoice') ? $payment->invoice?->number : null,
            'amount' => $payment->money($payment->amount_minor)->toArray($locale),
            // What is left to refund on it, from its loaded refunds; null when
            // they were not loaded. Display only — the refund Action decides
            // under the payment lock.
            'refundable' => $payment->relationLoaded('refunds')
                ? $payment->money(InvoiceSettlement::refundableOf($payment))->toArray($locale)
                : null,
            // Whether the provider can send this money back itself.
            'provider_refunds' => $payment->provider !== null
                && $this->providers->has($payment->provider)
                && $this->providers->get($payment->provider)->capabilities()->refunds,
            'method' => $payment->method->value,
            'status' => $payment->status->value,
            'source' => $payment->source->value,
            'manual_method_label' => $payment->manual_method_label,
            'manual_reference' => $payment->manual_reference,
            'provider' => $payment->provider,
            // What a customer types or taps to pay; non-secret by design.
            'provider_display_code' => $payment->provider_display_code,
            'checkout_url' => $payment->status->value === 'pending' ? $payment->checkout_url : null,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'collected_by' => $payment->collected_by_label,
            'initiated_at' => $payment->initiated_at->toIso8601String(),
            'succeeded_at' => $payment->succeeded_at?->toIso8601String(),
            'failed_at' => $payment->failed_at?->toIso8601String(),
            'cancelled_at' => $payment->cancelled_at?->toIso8601String(),
            'failure_code' => $payment->failure_code,
            'refunds' => $payment->relationLoaded('refunds')
                ? array_values($payment->refunds->map(fn (Refund $refund): array => $this->refund($refund))->all())
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refund(Refund $refund): array
    {
        return [
            'uuid' => $refund->uuid,
            'amount' => Money::fromMinor($refund->amount_minor, Currency::tryFrom($refund->currency) ?? Currency::default())->toArray(app()->getLocale()),
            'method' => $refund->method->value,
            'status' => $refund->status->value,
            'reason' => $refund->reason,
            'requested_by' => $refund->requested_by_label,
            'requested_at' => $refund->requested_at->toIso8601String(),
            'succeeded_at' => $refund->succeeded_at?->toIso8601String(),
            'failed_at' => $refund->failed_at?->toIso8601String(),
            'failure_code' => $refund->failure_code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function gatewayAccount(GatewayAccount $account): array
    {
        $provider = $this->providers->has($account->provider) ? $this->providers->get($account->provider) : null;
        $capabilities = $provider?->capabilities();

        return [
            'uuid' => $account->uuid,
            'provider' => $account->provider,
            'provider_name' => $provider?->displayName() ?? $account->provider,
            'display_name' => $account->display_name,
            'environment' => $account->environment->value,
            'enabled' => $account->enabled,
            'configured' => $account->isConfigured(),
            'safe_identifier' => $account->safe_identifier,
            'configured_at' => $account->configured_at?->toIso8601String(),
            'configured_by' => $account->configured_by_label,
            'supports_refunds' => $capabilities !== null && $capabilities->refunds,
            'supports_cancellation' => $capabilities !== null && $capabilities->cancellation,
        ];
    }

    /**
     * The providers a manager may set up, and what each asks for — field NAMES
     * only.
     *
     * @return list<array<string, mixed>>
     */
    public function providers(): array
    {
        return array_map(static fn ($provider): array => [
            'code' => $provider->code(),
            'name' => $provider->displayName(),
            'available' => $provider->capabilities()->available,
            'environments' => array_map(static fn ($environment): string => $environment->value, $provider->capabilities()->environments),
            'credential_fields' => $provider->credentialFields(),
            'refunds' => $provider->capabilities()->refunds,
        ], $this->providers->all());
    }

    public static function methodLabel(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Cash => __('Cash'),
            PaymentMethod::ManualElectronic => __('Manual transfer'),
            PaymentMethod::Gateway => __('Online'),
        };
    }
}
