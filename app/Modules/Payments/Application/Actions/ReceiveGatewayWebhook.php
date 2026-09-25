<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditSource;
use App\Modules\Payments\Application\GatewayConnections;
use App\Modules\Payments\Application\GatewaySettlement;
use App\Modules\Payments\Domain\Data\InboundWebhook;
use App\Modules\Payments\Domain\Enums\WebhookResult;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Exceptions\ProviderRequestFailed;
use App\Modules\Payments\Domain\Exceptions\WebhookRejected;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;

/**
 * A provider callback, from the door to the ledger.
 *
 *   1. the center was resolved from its public key (`public.tenant`) — no staff
 *      authentication exists on this path, and none is expected
 *   2. find the gateway account by its public uuid, enabled or not: a disabled
 *      account still verifies callbacks for payments already in flight
 *   3. the adapter reads the callback — verifying its signature when the
 *      provider signs, refusing it when the signature is wrong
 *   4. find the payment by THIS account and the provider's reference
 *   5. what is true? the verified callback status, or — for unsigned providers —
 *      an authenticated status query with the center's own credentials
 *   6. `GatewaySettlement` applies it atomically, idempotently
 *
 * An unknown account or a rejected callback throws {@see WebhookRejected}; the
 * controller answers with nothing that helps a forger. A callback for a payment
 * this account does not have is acknowledged and changes nothing — the answer
 * is the same as for a real one, so references cannot be probed
 * (docs/19-PAYMENTS.md §§21–24).
 *
 * No entitlement is checked, deliberately (§23).
 */
final class ReceiveGatewayWebhook
{
    public function __construct(
        private readonly GatewayConnections $connections,
        private readonly GatewaySettlement $settlement,
    ) {}

    /**
     * @return WebhookResult|null null when there is no such payment on this account
     *
     * @throws WebhookRejected
     * @throws ProviderRequestFailed when the provider's status could not be read — retryable
     */
    public function __invoke(string $accountUuid, InboundWebhook $webhook): ?WebhookResult
    {
        /** @var GatewayAccount|null $account */
        $account = GatewayAccount::query()->where('uuid', $accountUuid)->first();

        if (! $account instanceof GatewayAccount) {
            throw WebhookRejected::because('account_unknown');
        }

        try {
            $provider = $this->connections->provider($account);
            $credentials = $this->connections->credentials($account);
        } catch (PaymentFailed) {
            throw WebhookRejected::because('account_unusable');
        }

        $notification = $provider->readNotification($credentials, $webhook);

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('gateway_account_id', $account->getKey())
            ->where('provider_payment_reference', $notification->providerReference)
            ->first();

        if (! $payment instanceof Payment) {
            return null;
        }

        try {
            $status = $notification->status ?? $provider->queryPayment($credentials, $notification->providerReference);
        } catch (PaymentFailed) {
            throw WebhookRejected::because('status_unverifiable');
        }

        $fingerprint = hash('sha256', implode('|', [
            $account->provider,
            $account->uuid,
            $status->providerReference,
            $status->state->value,
            $notification->providerEventId ?? '',
        ]));

        return $this->settlement->apply(
            $payment,
            $status,
            new Actor(ActorType::Integration, AuditSource::System, null, $account->provider),
            [
                'account_id' => (int) $account->getKey(),
                'provider' => $account->provider,
                'fingerprint' => $fingerprint,
                'event_type' => 'payment.'.$status->state->value,
                'signature_verified' => $notification->signatureVerified,
                'provider_event_id' => $notification->providerEventId,
            ],
        );
    }
}
