<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Payments\Application\InvoicePaymentLock;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Application\PaymentsAccess;
use App\Modules\Payments\Application\PaymentsAudit;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentSource;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Events\PaymentSucceeded;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Domain\Enums\ShiftStatus;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Money taken at the desk: cash, or an electronic transfer the desk confirmed.
 *
 *     BEGIN
 *       lock the sale, then the invoice          (InvoicePaymentLock)
 *       voided?                                  → refuse
 *       available = total − succeeded − pending  (InvoiceSettlement, under lock)
 *       amount ≤ available                       → else refuse
 *       cash: lock the collector's open shift    → none → refuse
 *       write the payment, already succeeded
 *       PaymentSucceeded → Finance writes the ledger collection
 *       audit
 *     COMMIT
 *
 * Two desks taking the last 50,000 at once serialise on the invoice lock; the
 * second finds nothing left and is refused (docs/19-PAYMENTS.md §11).
 *
 * ## Only `pos`
 *
 * A center without online payments still takes cash — `payments` is never asked
 * (§2).
 *
 * ## Manual electronic is staff-confirmed
 *
 * It records what the desk checked — "FIB transfer", a reference — and nothing
 * about it claims a provider verified it (§14). It does not touch the drawer, so
 * it needs no shift.
 *
 * ## Idempotent
 *
 * A double-submitted "take payment" carries the same token and receives the
 * payment it already created; the unique index backs the reread.
 */
final class CollectDeskPayment
{
    public const MAX_TOKEN = 64;

    public function __construct(
        private readonly PaymentsAccess $access,
        private readonly InvoicePaymentLock $lock,
        private readonly InvoiceSettlement $settlement,
        private readonly PaymentsAudit $audit,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @throws PaymentFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        Invoice $invoice,
        User $actingUser,
        PaymentMethod $method,
        int $amountMinor,
        ?string $manualLabel = null,
        ?string $manualReference = null,
        ?string $idempotencyToken = null,
        ?CarbonImmutable $now = null,
    ): Payment {
        if (! $method->isDeskMethod()) {
            throw PaymentFailed::policy('An online payment is started through the gateway, not recorded at the desk.');
        }

        $this->access->ensureDesk($actingUser, Permission::PaymentCollect, $invoice->branch_id, 'You may not take payments.');

        if ($amountMinor <= 0) {
            throw PaymentFailed::policy('A payment must be more than zero.');
        }

        [$label, $reference] = $this->manualDetails($method, $manualLabel, $manualReference);
        $token = self::token($idempotencyToken);

        if ($token !== null && ($existing = $this->byToken($token)) instanceof Payment) {
            return $this->sameRequest($existing, $invoice, $method, $amountMinor);
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        try {
            /** @var Payment $payment */
            $payment = DB::connection('tenant')->transaction(function () use ($invoice, $actingUser, $method, $amountMinor, $label, $reference, $token, $at): Payment {
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

                $shift = $method->movesDrawerCash() ? $this->openShift($actingUser, $locked->branch_id) : null;

                /** @var Payment $payment */
                $payment = Payment::query()->create([
                    'invoice_id' => $locked->getKey(),
                    'branch_id' => $locked->branch_id,
                    'amount_minor' => $amountMinor,
                    'currency' => $locked->currency,
                    'method' => $method,
                    'status' => PaymentStatus::Succeeded,
                    'source' => PaymentSource::Desk,
                    'manual_method_label' => $label,
                    'manual_reference' => $reference,
                    'cashier_shift_id' => $shift?->getKey(),
                    'idempotency_token' => $token,
                    'collected_by_id' => $actingUser->uuid,
                    'collected_by_label' => $actingUser->name,
                    'initiated_at' => $at,
                    'succeeded_at' => $at,
                ]);

                // Finance records the collection in THIS transaction.
                $this->events->dispatch(new PaymentSucceeded((int) $payment->getKey()));

                $this->audit->record(
                    $method === PaymentMethod::Cash ? 'payment.cash_recorded' : 'payment.manual_recorded',
                    Actor::staff($actingUser),
                    $payment,
                    $payment->uuid,
                    after: [
                        'status' => PaymentStatus::Succeeded->value,
                        'amount_minor' => $amountMinor,
                        'currency' => $locked->currency,
                        'method' => $method->value,
                    ],
                    meta: array_filter([
                        'invoice' => $locked->uuid,
                        'shift' => $shift?->uuid,
                        'manual_method_label' => $label,
                    ], static fn (mixed $value): bool => $value !== null),
                );

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same token raced in from a second tab and won.
            $existing = $token === null ? null : $this->byToken($token);

            if (! $existing instanceof Payment) {
                throw $e;
            }

            return $this->sameRequest($existing, $invoice, $method, $amountMinor);
        }

        return $payment;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     *
     * @throws PaymentFailed
     */
    private function manualDetails(PaymentMethod $method, ?string $label, ?string $reference): array
    {
        if ($method === PaymentMethod::Cash) {
            return [null, null];
        }

        $label = trim((string) $label);
        $reference = $reference === null ? null : trim($reference);

        if (mb_strlen($label) < 2 || mb_strlen($label) > 60) {
            throw PaymentFailed::policy('Say how it was paid — for example "FIB transfer" or "bank transfer".');
        }

        if ($reference !== null && mb_strlen($reference) > 120) {
            throw PaymentFailed::policy('That reference is too long.');
        }

        return [$label, $reference === '' ? null : $reference];
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
            throw PaymentFailed::policy('Open your cashier shift at this branch before taking cash.');
        }

        return $shift;
    }

    /**
     * @throws PaymentFailed
     */
    public static function token(?string $token): ?string
    {
        $token = $token === null ? null : trim($token);

        if ($token === null || $token === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9\-_]{8,'.self::MAX_TOKEN.'}$/', $token) !== 1) {
            throw PaymentFailed::policy('That request token is not valid.');
        }

        return $token;
    }

    private function byToken(string $token): ?Payment
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()->where('idempotency_token', $token)->first();

        return $payment;
    }

    /**
     * A repeat returns the original — but only if it IS a repeat.
     *
     * @throws PaymentFailed
     */
    private function sameRequest(Payment $existing, Invoice $invoice, PaymentMethod $method, int $amountMinor): Payment
    {
        if ($existing->invoice_id !== $invoice->getKey() || $existing->method !== $method || $existing->amount_minor !== $amountMinor) {
            throw PaymentFailed::policy('That request token was already used for a different payment.');
        }

        return $existing;
    }
}
