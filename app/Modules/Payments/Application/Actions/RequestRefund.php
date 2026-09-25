<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Payments\Application\GatewayConnections;
use App\Modules\Payments\Application\PaymentsAccess;
use App\Modules\Payments\Application\PaymentsAudit;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\RefundStatus;
use App\Modules\Payments\Domain\Events\RefundSucceeded;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Exceptions\ProviderRequestFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use App\Modules\Sales\Domain\Enums\ShiftStatus;
use App\Modules\Sales\Domain\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Returns money against one successful payment.
 *
 * ## How much may still go back
 *
 * Under a lock on the PAYMENT row:
 *
 *     refundable = payment − succeeded refunds − pending refunds
 *
 * Two managers refunding the same payment at once serialise on that lock; the
 * second sees what the first reserved (docs/19-PAYMENTS.md §§26, 66).
 *
 * ## How it goes back
 *
 *   cash                the refunder's open drawer pays it: needs their shift
 *   manual electronic   a transfer the desk made and confirmed
 *   gateway             the provider's refund API — ONLY when the provider
 *                       documents one; otherwise refused as unsupported, never
 *                       recorded as if it happened
 *
 * A cash or manual refund against an online payment is allowed: handing the
 * customer their money back in cash is a real movement of real money.
 *
 * A provider refund reserves first, calls the provider outside any
 * transaction, then records the answer — succeeded with its ledger debit, or
 * failed with none.
 *
 * ## What a refund does not do
 *
 * Touch the payment, touch the invoice, or reopen the invoice's balance. The
 * invoice stays paid; the net collected goes down (§9).
 */
final class RequestRefund
{
    public function __construct(
        private readonly PaymentsAccess $access,
        private readonly GatewayConnections $connections,
        private readonly PaymentsAudit $audit,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @throws PaymentFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        Payment $payment,
        User $actingUser,
        PaymentMethod $method,
        int $amountMinor,
        string $reason,
        ?string $idempotencyToken = null,
        ?CarbonImmutable $now = null,
    ): Refund {
        if ($method === PaymentMethod::Gateway) {
            $this->access->ensureGateway($actingUser, Permission::PaymentRefund, $payment->branch_id, 'You may not refund payments.');
        } else {
            $this->access->ensureDesk($actingUser, Permission::PaymentRefund, $payment->branch_id, 'You may not refund payments.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 190) {
            throw PaymentFailed::policy('A refund needs a reason.');
        }

        if ($amountMinor <= 0) {
            throw PaymentFailed::policy('A refund must be more than zero.');
        }

        if ($method === PaymentMethod::Gateway) {
            $this->assertProviderRefundable($payment);
        }

        $token = CollectDeskPayment::token($idempotencyToken);

        if ($token !== null && ($existing = $this->byToken($token)) instanceof Refund) {
            return $this->sameRequest($existing, $payment, $method, $amountMinor);
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        try {
            /** @var Refund $refund */
            $refund = DB::connection('tenant')->transaction(function () use ($payment, $actingUser, $method, $amountMinor, $reason, $token, $at): Refund {
                /** @var Payment $locked */
                $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== PaymentStatus::Succeeded) {
                    throw PaymentFailed::invalidTransition('Only a successful payment can be refunded.');
                }

                $refundable = $locked->amount_minor - (int) Refund::query()
                    ->where('payment_id', $locked->getKey())
                    ->whereIn('status', [RefundStatus::Succeeded->value, RefundStatus::Pending->value])
                    ->sum('amount_minor');

                if ($amountMinor > $refundable) {
                    throw PaymentFailed::policy('That is more than is left to refund on this payment.', [
                        'refundable_minor' => max(0, $refundable),
                    ]);
                }

                $shift = $method->movesDrawerCash() ? $this->openShift($actingUser, $locked->branch_id) : null;
                $immediate = $method !== PaymentMethod::Gateway;

                /** @var Refund $refund */
                $refund = Refund::query()->create([
                    'payment_id' => $locked->getKey(),
                    'branch_id' => $locked->branch_id,
                    'amount_minor' => $amountMinor,
                    'currency' => $locked->currency,
                    'method' => $method,
                    'provider' => $method === PaymentMethod::Gateway ? $locked->provider : null,
                    'status' => $immediate ? RefundStatus::Succeeded : RefundStatus::Pending,
                    'reason' => $reason,
                    'cashier_shift_id' => $shift?->getKey(),
                    'idempotency_token' => $token,
                    'requested_by_id' => $actingUser->uuid,
                    'requested_by_label' => $actingUser->name,
                    'requested_at' => $at,
                    'succeeded_at' => $immediate ? $at : null,
                ]);

                if ($immediate) {
                    $this->events->dispatch(new RefundSucceeded((int) $refund->getKey()));
                }

                $this->audit->record($immediate ? 'refund.recorded' : 'refund.requested', Actor::staff($actingUser), $refund, $refund->uuid,
                    after: [
                        'status' => $refund->status->value,
                        'amount_minor' => $amountMinor,
                        'method' => $method->value,
                    ],
                    meta: array_filter(['payment' => $locked->uuid, 'shift' => $shift?->uuid], static fn (mixed $value): bool => $value !== null),
                    reason: $reason,
                    severity: AuditSeverity::Warning,
                );

                return $refund;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $token === null ? null : $this->byToken($token);

            if (! $existing instanceof Refund) {
                throw $e;
            }

            return $this->sameRequest($existing, $payment, $method, $amountMinor);
        }

        if ($refund->status === RefundStatus::Pending) {
            return $this->throughProvider($refund, $payment, $actingUser, $at);
        }

        return $refund;
    }

    /**
     * @throws PaymentFailed
     */
    private function throughProvider(Refund $refund, Payment $payment, User $actingUser, CarbonImmutable $at): Refund
    {
        /** @var GatewayAccount $account */
        $account = GatewayAccount::query()->whereKey($payment->gateway_account_id)->firstOrFail();

        try {
            $result = $this->connections->provider($account)->refund(
                $this->connections->credentials($account),
                (string) $payment->provider_payment_reference,
                $refund->amount_minor,
                $refund->currency,
            );
        } catch (PaymentFailed $unsupported) {
            // Definitely nothing was refunded: release the reservation.
            $this->finish($refund, false, null, 'provider_unsupported', $actingUser, $at);

            throw $unsupported;
        } catch (ProviderRequestFailed) {
            // The provider may or may not have moved the money. The refund stays
            // pending — its amount still reserved — for a person to resolve with
            // the provider, rather than guessed either way.
            throw PaymentFailed::providerUnavailable('The provider did not confirm the refund. It is recorded as pending; check it with the provider before trying again.');
        }

        return $this->finish($refund, $result->succeeded, $result->providerRefundReference, $result->failureCode ?? 'provider_declined', $actingUser, $at);
    }

    private function finish(Refund $refund, bool $succeeded, ?string $reference, string $failureCode, User $actingUser, CarbonImmutable $at): Refund
    {
        /** @var Refund $finished */
        $finished = DB::connection('tenant')->transaction(function () use ($refund, $succeeded, $reference, $failureCode, $actingUser, $at): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== RefundStatus::Pending) {
                return $locked;
            }

            $locked->forceFill($succeeded
                ? ['status' => RefundStatus::Succeeded, 'succeeded_at' => $at, 'provider_refund_reference' => $reference === null ? null : mb_substr($reference, 0, 128)]
                : ['status' => RefundStatus::Failed, 'failed_at' => $at, 'failure_code' => mb_substr($failureCode, 0, 64)]
            )->save();

            if ($succeeded) {
                $this->events->dispatch(new RefundSucceeded((int) $locked->getKey()));
            }

            $this->audit->record($succeeded ? 'refund.succeeded' : 'refund.failed', Actor::staff($actingUser), $locked, $locked->uuid,
                after: ['status' => $locked->status->value],
                before: ['status' => RefundStatus::Pending->value],
                meta: $succeeded ? [] : ['failure_code' => $failureCode],
                severity: AuditSeverity::Warning,
            );

            return $locked;
        });

        return $finished;
    }

    /**
     * @throws PaymentFailed
     */
    private function assertProviderRefundable(Payment $payment): void
    {
        if ($payment->method !== PaymentMethod::Gateway || $payment->gateway_account_id === null || $payment->provider_payment_reference === null) {
            throw PaymentFailed::policy('Only an online payment can be refunded through its provider. Refund this one in cash or by transfer.');
        }

        /** @var GatewayAccount|null $account */
        $account = GatewayAccount::query()->whereKey($payment->gateway_account_id)->first();

        if (! $account instanceof GatewayAccount || ! $this->connections->provider($account)->capabilities()->refunds) {
            throw PaymentFailed::unsupported('This provider has no refund API. Refund this payment in cash or by a manual transfer.');
        }
    }

    /**
     * @throws PaymentFailed
     */
    private function openShift(User $actingUser, int $branchId): CashierShift
    {
        /** @var CashierShift|null $shift */
        $shift = CashierShift::query()
            ->where('active_user_id', $actingUser->getKey())
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if (! $shift instanceof CashierShift || $shift->status !== ShiftStatus::Open) {
            throw PaymentFailed::policy('Open your cashier shift at this branch before refunding cash.');
        }

        return $shift;
    }

    private function byToken(string $token): ?Refund
    {
        /** @var Refund|null $refund */
        $refund = Refund::query()->where('idempotency_token', $token)->first();

        return $refund;
    }

    /**
     * @throws PaymentFailed
     */
    private function sameRequest(Refund $existing, Payment $payment, PaymentMethod $method, int $amountMinor): Refund
    {
        if ($existing->payment_id !== $payment->getKey() || $existing->method !== $method || $existing->amount_minor !== $amountMinor) {
            throw PaymentFailed::policy('That request token was already used for a different refund.');
        }

        return $existing;
    }
}
