<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Modules\Payments\Application\GatewayConnections;
use App\Modules\Payments\Application\InvoicePaymentLock;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Application\PaymentsAccess;
use App\Modules\Payments\Application\PaymentsAudit;
use App\Modules\Payments\Domain\Data\ProviderPaymentRequest;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentSource;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Exceptions\ProviderRequestFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Domain\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts an online payment through a branch's gateway — from the desk, or by
 * the customer from their invoice link.
 *
 * ## Reserve, then call, then record
 *
 *     TX 1  lock sale → invoice; available ≥ amount; create the payment PENDING
 *           — the amount is now RESERVED against the invoice             COMMIT
 *     call the provider — outside any transaction, holding no lock
 *     TX 2  success: store the provider's reference, code, link, expiry  COMMIT
 *           failure: mark the payment FAILED, releasing the reservation  COMMIT
 *
 * The reservation is what stops the double collection a naive design allows:
 * with 100 left, a pending gateway payment of 100 leaves 0 for the cashier, so
 * the provider's later "paid" can never make the center collect 200 against a
 * 100 invoice (docs/19-PAYMENTS.md §10).
 *
 * The provider is never called inside a transaction: a slow provider must not
 * hold the invoice lock, and every other desk, for ten seconds.
 *
 * ## Nothing here settles anything
 *
 * The payment stays pending until a verified callback or an authenticated
 * status query says otherwise (`GatewaySettlement`). A browser coming back from
 * the provider proves nothing (§30).
 *
 * ## Entitlement
 *
 * `payments`, for the desk and the public link alike: this is the online
 * payments capability (§2).
 */
final class InitiateGatewayPayment
{
    public function __construct(
        private readonly PaymentsAccess $access,
        private readonly Entitlements $entitlements,
        private readonly InvoicePaymentLock $lock,
        private readonly InvoiceSettlement $settlement,
        private readonly GatewayConnections $connections,
        private readonly PaymentsAudit $audit,
    ) {}

    /**
     * From the desk: the collecting user's permission and branch.
     *
     * @throws PaymentFailed
     * @throws AuthorizationException
     */
    public function fromDesk(Invoice $invoice, GatewayAccount $account, User $actingUser, int $amountMinor, ?string $idempotencyToken = null, ?CarbonImmutable $now = null): Payment
    {
        $this->access->ensureGateway($actingUser, Permission::PaymentCollect, $invoice->branch_id, 'You may not take payments.');

        return $this->initiate($invoice, $account, Actor::staff($actingUser), PaymentSource::Desk, $amountMinor, $idempotencyToken, $now, $actingUser);
    }

    /**
     * From the customer's invoice link. The caller has already resolved the
     * invoice from its share secret — holding the link IS the authority to pay
     * it; there is no staff user.
     *
     * @throws PaymentFailed
     */
    public function fromPublicLink(Invoice $invoice, GatewayAccount $account, int $amountMinor, ?string $idempotencyToken = null, ?CarbonImmutable $now = null): Payment
    {
        $this->entitlements->ensure('payments');

        $actor = new Actor(ActorType::Guest, AuditSource::Web, null, 'invoice link');

        return $this->initiate($invoice, $account, $actor, PaymentSource::PublicLink, $amountMinor, $idempotencyToken, $now, null);
    }

    /**
     * @throws PaymentFailed
     */
    private function initiate(Invoice $invoice, GatewayAccount $account, Actor $actor, PaymentSource $source, int $amountMinor, ?string $idempotencyToken, ?CarbonImmutable $now, ?User $actingUser): Payment
    {
        $provider = $this->connections->provider($account);
        $capabilities = $provider->capabilities();

        if ($account->branch_id !== $invoice->branch_id) {
            // A branch's merchant account collects for that branch only.
            throw PaymentFailed::policy('That payment gateway belongs to a different branch.');
        }

        if (! $account->enabled || ! $account->isConfigured() || ! $capabilities->available) {
            throw PaymentFailed::policy('That payment gateway is not available.');
        }

        if (! $capabilities->supportsEnvironment($account->environment)) {
            throw PaymentFailed::unsupported('That payment gateway is not available in its configured environment.');
        }

        $currency = Currency::tryFrom($invoice->currency);

        if ($currency === null || ! $capabilities->supportsCurrency($currency)) {
            throw PaymentFailed::unsupported('That payment gateway does not accept this invoice\'s currency.');
        }

        if ($amountMinor <= 0) {
            throw PaymentFailed::policy('A payment must be more than zero.');
        }

        $token = CollectDeskPayment::token($idempotencyToken);

        if ($token !== null && ($existing = $this->byToken($token)) instanceof Payment) {
            return $this->sameRequest($existing, $invoice, $account, $amountMinor);
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        try {
            /** @var Payment $payment */
            $payment = DB::connection('tenant')->transaction(function () use ($invoice, $account, $actor, $source, $amountMinor, $token, $at, $actingUser): Payment {
                [$sale, $locked] = $this->lock->lock($invoice);

                if ($sale->isVoided()) {
                    throw PaymentFailed::policy('That sale was voided. No payment can be taken against its invoice.');
                }

                $summary = $this->settlement->forInvoice($locked);

                if ($amountMinor > $summary->availableCollectibleMinor) {
                    throw PaymentFailed::policy('That is more than is left to collect on this invoice.', [
                        'available_minor' => $summary->availableCollectibleMinor,
                        'pending_minor' => $summary->pendingMinor,
                    ]);
                }

                /** @var Payment $payment */
                $payment = Payment::query()->create([
                    'invoice_id' => $locked->getKey(),
                    'branch_id' => $locked->branch_id,
                    'amount_minor' => $amountMinor,
                    'currency' => $locked->currency,
                    'method' => PaymentMethod::Gateway,
                    'status' => PaymentStatus::Pending,
                    'source' => $source,
                    'gateway_account_id' => $account->getKey(),
                    'provider' => $account->provider,
                    'idempotency_token' => $token,
                    'collected_by_id' => $actingUser?->uuid,
                    'collected_by_label' => $actingUser?->name,
                    'initiated_at' => $at,
                ]);

                $this->audit->record('payment.gateway_initiated', $actor, $payment, $payment->uuid,
                    after: ['status' => PaymentStatus::Pending->value, 'amount_minor' => $amountMinor, 'currency' => $locked->currency],
                    meta: ['invoice' => $locked->uuid, 'provider' => $account->provider, 'source' => $source->value],
                );

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $token === null ? null : $this->byToken($token);

            if (! $existing instanceof Payment) {
                throw $e;
            }

            return $this->sameRequest($existing, $invoice, $account, $amountMinor);
        }

        try {
            $created = $provider->createPayment(
                $this->connections->credentials($account),
                new ProviderPaymentRequest(
                    merchantReference: $payment->uuid,
                    amountMinor: $amountMinor,
                    currency: $currency,
                    description: 'Invoice '.$invoice->number,
                    callbackUrl: $this->connections->callbackUrl($account),
                ),
            );
        } catch (ProviderRequestFailed|PaymentFailed $failure) {
            // The provider issued nothing usable, so nobody can pay this
            // attempt: release the reservation now instead of leaving the
            // invoice blocked until somebody notices.
            $this->release($payment, $actor, $failure instanceof ProviderRequestFailed ? 'provider_create_failed' : 'provider_unsupported', $at);

            throw $failure instanceof PaymentFailed ? $failure : PaymentFailed::providerUnavailable();
        }

        DB::connection('tenant')->transaction(function () use ($payment, $created): void {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'provider_payment_reference' => $created->providerReference,
                'provider_display_code' => $created->displayCode,
                'checkout_url' => $created->checkoutUrl,
                'expires_at' => $created->expiresAt,
            ])->save();
        });

        return $payment->refresh();
    }

    private function release(Payment $payment, Actor $actor, string $failureCode, CarbonImmutable $at): void
    {
        DB::connection('tenant')->transaction(function () use ($payment, $actor, $failureCode, $at): void {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::Pending) {
                return;
            }

            $locked->forceFill([
                'status' => PaymentStatus::Failed,
                'failed_at' => $at,
                'failure_code' => $failureCode,
            ])->save();

            $this->audit->record('payment.gateway_failed', $actor, $locked, $locked->uuid,
                after: ['status' => PaymentStatus::Failed->value],
                meta: ['failure_code' => $failureCode, 'provider' => $locked->provider],
            );
        });
    }

    private function byToken(string $token): ?Payment
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()->where('idempotency_token', $token)->first();

        return $payment;
    }

    /**
     * @throws PaymentFailed
     */
    private function sameRequest(Payment $existing, Invoice $invoice, GatewayAccount $account, int $amountMinor): Payment
    {
        if ($existing->invoice_id !== $invoice->getKey()
            || $existing->gateway_account_id !== $account->getKey()
            || $existing->amount_minor !== $amountMinor) {
            throw PaymentFailed::policy('That request token was already used for a different payment.');
        }

        return $existing;
    }
}
