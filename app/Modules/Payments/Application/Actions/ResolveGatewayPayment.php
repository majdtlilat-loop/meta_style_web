<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Payments\Application\GatewayConnections;
use App\Modules\Payments\Application\GatewaySettlement;
use App\Modules\Payments\Application\PaymentsAccess;
use App\Modules\Payments\Application\PaymentsAudit;
use App\Modules\Payments\Domain\Data\ProviderPaymentStatus;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\WebhookResult;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Exceptions\ProviderRequestFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Staff resolving a gateway payment that is still pending: ask the provider, or
 * cancel it.
 *
 * Both exist so a stuck pending payment — a customer who never paid, a callback
 * that never came — does not hold its invoice's reservation for ever.
 *
 * ## Refresh
 *
 * Asks the provider, with the center's credentials, what the payment's status
 * is, and applies the answer through `GatewaySettlement` exactly as a callback
 * would. The same verification: amount and currency must match.
 *
 * ## Cancel
 *
 * Only through the provider, when it supports cancellation: the provider is told
 * first, then asked what is true, and THAT is applied. Cancelling locally while
 * the provider might still take the customer's money would release the
 * reservation and invite exactly the double collection the reservation exists to
 * prevent. The one local cancel is for an attempt the provider never issued —
 * no reference, so nobody can pay it.
 *
 * Neither needs `payments`: resolving money that is already moving is not a new
 * online-payments operation (docs/19-PAYMENTS.md §§3, 23).
 */
final class ResolveGatewayPayment
{
    public function __construct(
        private readonly PaymentsAccess $access,
        private readonly GatewayConnections $connections,
        private readonly GatewaySettlement $settlement,
        private readonly PaymentsAudit $audit,
    ) {}

    /**
     * @throws PaymentFailed
     * @throws AuthorizationException
     */
    public function refresh(Payment $payment, User $actingUser): WebhookResult
    {
        $this->access->authorize($actingUser, Permission::PaymentCollect, $payment->branch_id, 'You may not manage payments.');

        [$account, $reference] = $this->pendingGateway($payment);

        return $this->settlement->apply($payment, $this->query($account, $reference), Actor::staff($actingUser));
    }

    /**
     * @throws PaymentFailed
     * @throws AuthorizationException
     */
    public function cancel(Payment $payment, User $actingUser, ?CarbonImmutable $now = null): Payment
    {
        $this->access->authorize($actingUser, Permission::PaymentCollect, $payment->branch_id, 'You may not manage payments.');

        if ($payment->method !== PaymentMethod::Gateway || $payment->status !== PaymentStatus::Pending) {
            throw PaymentFailed::invalidTransition('Only an online payment that is still pending can be cancelled.');
        }

        if ($payment->provider_payment_reference === null) {
            return $this->cancelUnissued($payment, $actingUser, ($now ?? CarbonImmutable::now())->utc());
        }

        [$account, $reference] = $this->pendingGateway($payment);
        $provider = $this->connections->provider($account);

        if (! $provider->capabilities()->cancellation) {
            throw PaymentFailed::unsupported('This provider cannot cancel a payment. Wait for it to expire or be reported.');
        }

        try {
            $provider->cancelPayment($this->connections->credentials($account), $reference);
        } catch (ProviderRequestFailed) {
            throw PaymentFailed::providerUnavailable();
        }

        // What is true now — cancelled, or paid a moment before the cancel.
        $this->settlement->apply($payment, $this->query($account, $reference), Actor::staff($actingUser));

        return $payment->refresh();
    }

    /**
     * @return array{0: GatewayAccount, 1: string}
     *
     * @throws PaymentFailed
     */
    private function pendingGateway(Payment $payment): array
    {
        if ($payment->method !== PaymentMethod::Gateway || $payment->status !== PaymentStatus::Pending) {
            throw PaymentFailed::invalidTransition('That payment is not an online payment awaiting the provider.');
        }

        /** @var GatewayAccount|null $account */
        $account = GatewayAccount::query()->whereKey($payment->gateway_account_id)->first();

        if (! $account instanceof GatewayAccount || $payment->provider_payment_reference === null) {
            throw PaymentFailed::policy('That payment has no provider reference to check.');
        }

        return [$account, $payment->provider_payment_reference];
    }

    /**
     * @throws PaymentFailed
     */
    private function query(GatewayAccount $account, string $reference): ProviderPaymentStatus
    {
        try {
            return $this->connections->provider($account)->queryPayment($this->connections->credentials($account), $reference);
        } catch (ProviderRequestFailed) {
            throw PaymentFailed::providerUnavailable();
        }
    }

    private function cancelUnissued(Payment $payment, User $actingUser, CarbonImmutable $at): Payment
    {
        /** @var Payment $cancelled */
        $cancelled = DB::connection('tenant')->transaction(function () use ($payment, $actingUser, $at): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::Pending || $locked->provider_payment_reference !== null) {
                return $locked;
            }

            $locked->forceFill([
                'status' => PaymentStatus::Cancelled,
                'cancelled_at' => $at,
                'failure_code' => 'never_issued',
            ])->save();

            $this->audit->record('payment.gateway_cancelled', Actor::staff($actingUser), $locked, $locked->uuid,
                after: ['status' => PaymentStatus::Cancelled->value],
                before: ['status' => PaymentStatus::Pending->value],
                meta: ['failure_code' => 'never_issued'],
            );

            return $locked;
        });

        return $cancelled;
    }
}
