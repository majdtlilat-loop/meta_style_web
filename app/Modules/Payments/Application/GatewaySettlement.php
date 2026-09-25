<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Modules\Payments\Domain\Data\ProviderPaymentStatus;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\ProviderState;
use App\Modules\Payments\Domain\Enums\WebhookResult;
use App\Modules\Payments\Domain\Events\PaymentSucceeded;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies what a provider VERIFIABLY said about a pending gateway payment.
 *
 * The single place a gateway payment changes state after it was created. Its
 * input is always a {@see ProviderPaymentStatus} that came from a signed
 * callback or an authenticated status query — never a browser redirect, never
 * an unsigned body.
 *
 *     BEGIN
 *       record the callback's fingerprint           duplicate → return, nothing else
 *       lock the payment FOR UPDATE
 *       already final?                              → ignored
 *       UNPAID                                      → still pending
 *       PAID: reference, account, currency AND
 *             amount all match the payment?         → no  → NOT settled; security audit
 *                                                   → yes → succeeded;
 *                                                     PaymentSucceeded → ledger collection
 *       DECLINED                                    → failed    (reservation released)
 *       CANCELLED                                   → cancelled (reservation released)
 *       audit, mark the callback processed
 *     COMMIT
 *
 * A repeated "paid" finds its fingerprint, the payment already succeeded and the
 * ledger's unique source — three independent reasons it produces no second
 * collection (docs/19-PAYMENTS.md §§22, 63, 82).
 *
 * ## No entitlement check
 *
 * On purpose. A payment the center started while it owned `payments` finishes
 * here even if the package changed since: money already moving cannot depend on
 * today's plan (§23).
 */
final class GatewaySettlement
{
    public function __construct(
        private readonly PaymentsAudit $audit,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  array{account_id: int, provider: string, fingerprint: string, event_type: string, signature_verified: bool, provider_event_id: string|null}|null  $callback
     */
    public function apply(Payment $payment, ProviderPaymentStatus $status, Actor $actor, ?array $callback = null, ?CarbonImmutable $now = null): WebhookResult
    {
        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var WebhookResult $result */
        $result = DB::connection('tenant')->transaction(function () use ($payment, $status, $actor, $callback, $at): WebhookResult {
            if ($callback !== null && ! $this->claim($callback, $payment, $at)) {
                return WebhookResult::Duplicate;
            }

            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            $result = $this->transition($locked, $status, $actor, $at);

            if ($callback !== null) {
                WebhookEvent::query()
                    ->where('gateway_account_id', $callback['account_id'])
                    ->where('fingerprint', $callback['fingerprint'])
                    ->update([
                        'result' => $result->value,
                        'processed_at' => $at,
                        'error_code' => $result === WebhookResult::AmountMismatch ? 'amount_or_currency_mismatch' : null,
                        'updated_at' => $at,
                    ]);
            }

            return $result;
        });

        return $result;
    }

    private function transition(Payment $locked, ProviderPaymentStatus $status, Actor $actor, CarbonImmutable $at): WebhookResult
    {
        if ($locked->status !== PaymentStatus::Pending) {
            return WebhookResult::Ignored;
        }

        return match ($status->state) {
            ProviderState::Unpaid => WebhookResult::StillPending,
            ProviderState::Paid => $this->succeed($locked, $status, $actor, $at),
            ProviderState::Declined => $this->end($locked, PaymentStatus::Failed, $status->failureCode ?? 'provider_declined', $actor, $at),
            ProviderState::Cancelled => $this->end($locked, PaymentStatus::Cancelled, $status->failureCode ?? 'provider_cancelled', $actor, $at),
        };
    }

    private function succeed(Payment $locked, ProviderPaymentStatus $status, Actor $actor, CarbonImmutable $at): WebhookResult
    {
        $matches = $locked->provider_payment_reference !== null
            && hash_equals($locked->provider_payment_reference, $status->providerReference)
            && $status->amountMinor !== null
            && $status->amountMinor === $locked->amount_minor
            && $status->currency === $locked->currency;

        if (! $matches) {
            // The provider reports money we did not ask for — a different
            // amount, currency or payment. Believing it would settle the wrong
            // sum; the payment stays pending, its reservation held, and a
            // person looks at it (§82). The figures go to the audit row, which
            // staff can read; nothing about the payer does.
            $this->audit->record('payment.gateway_mismatch', $actor, $locked, $locked->uuid,
                meta: [
                    'expected_minor' => $locked->amount_minor,
                    'expected_currency' => $locked->currency,
                    'reported_minor' => $status->amountMinor,
                    'reported_currency' => $status->currency,
                    'provider' => $locked->provider,
                ],
                severity: AuditSeverity::Critical,
                category: AuditCategory::Security,
            );

            return WebhookResult::AmountMismatch;
        }

        $locked->forceFill([
            'status' => PaymentStatus::Succeeded,
            'succeeded_at' => $at,
        ])->save();

        $this->events->dispatch(new PaymentSucceeded((int) $locked->getKey()));

        $this->audit->record('payment.gateway_succeeded', $actor, $locked, $locked->uuid,
            after: ['status' => PaymentStatus::Succeeded->value, 'amount_minor' => $locked->amount_minor],
            before: ['status' => PaymentStatus::Pending->value],
            meta: ['provider' => $locked->provider],
        );

        return WebhookResult::Processed;
    }

    private function end(Payment $locked, PaymentStatus $to, string $failureCode, Actor $actor, CarbonImmutable $at): WebhookResult
    {
        $locked->forceFill([
            'status' => $to,
            ($to === PaymentStatus::Failed ? 'failed_at' : 'cancelled_at') => $at,
            'failure_code' => mb_substr($failureCode, 0, 64),
        ])->save();

        $this->audit->record($to === PaymentStatus::Failed ? 'payment.gateway_failed' : 'payment.gateway_cancelled', $actor, $locked, $locked->uuid,
            after: ['status' => $to->value],
            before: ['status' => PaymentStatus::Pending->value],
            meta: ['provider' => $locked->provider, 'failure_code' => $failureCode],
        );

        return WebhookResult::Processed;
    }

    /**
     * Inserts the callback's fingerprint; false when it is already there.
     *
     * @param  array{account_id: int, provider: string, fingerprint: string, event_type: string, signature_verified: bool, provider_event_id: string|null}  $callback
     */
    private function claim(array $callback, Payment $payment, CarbonImmutable $at): bool
    {
        $inserted = DB::connection('tenant')->table('payment_webhook_events')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'gateway_account_id' => $callback['account_id'],
            'provider' => $callback['provider'],
            'provider_event_id' => $callback['provider_event_id'],
            'fingerprint' => $callback['fingerprint'],
            'event_type' => $callback['event_type'],
            'signature_verified' => $callback['signature_verified'],
            'payment_id' => $payment->getKey(),
            'result' => WebhookResult::Processed->value,
            'received_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $inserted === 1;
    }
}
