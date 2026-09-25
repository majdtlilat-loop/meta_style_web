<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Database\AfterCommit;
use App\Kernel\Reconciliation\Contracts\Reconciler;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Loyalty\Domain\Data\EarningState;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use App\Modules\Loyalty\Domain\Models\LoyaltyInvoiceRule;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\RefundStatus;
use App\Modules\Payments\Domain\Events\PaymentSucceeded;
use App\Modules\Payments\Domain\Events\RefundSucceeded;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Events\JourneyCompleted;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loyalty earning and its refund reversals — AFTER the money commits, and
 * repairable from the money without changing what the customer should have got.
 *
 * ## Never able to undo real money
 *
 * Payments raises `PaymentSucceeded` / `RefundSucceeded` inside the transaction
 * that moved the money. The handlers here READ what is true at that instant
 * (`EarningRules::capture()`, which never throws) and otherwise only schedule
 * work for after that transaction commits (`AfterCommit`). A failure afterwards
 * is reported and dropped; the payment is already true and is still reported as
 * true (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §1).
 *
 * ## The answer does not depend on when it is written
 *
 * An invoice is replayed from its own canonical events — its successful
 * payments and their refunds, in the order they happened. Each event is judged
 * by the state captured WHEN IT HAPPENED: whether the center owned `loyalty`
 * then, and which rule version was effective then (`EarningRules`). So:
 *
 *   - money collected while the rules said 10 points earns 10 points, even when
 *     the repair runs after the rules changed to 20;
 *   - points carry the expiry of the rule in force when they were earned,
 *     counted from the event's own time;
 *   - an earning row is dated by its payment, not by the repair;
 *   - money collected while `loyalty` was owned stays recoverable after it is
 *     taken away, and money collected during a gap never earns when it is
 *     granted back (§6).
 *
 * Reconciliation is only WHEN the missing row was written. A second run writes
 * nothing: every row is keyed on its source event.
 *
 * ## Why the walk, rather than "target minus earned"
 *
 * Each event's own points are the increase it caused in the invoice's target,
 * so a payment always earns what that payment earned — whatever order the
 * payments and refunds are synced in, and whichever of them failed first.
 * Refund reversals are the decrease, applied to the balance when they are
 * written (a debit is never backdated: the points it takes must be points the
 * customer actually has).
 */
final class LoyaltySync implements Reconciler
{
    /** How far back the per-customer sync before a balance change looks. */
    public const CUSTOMER_WINDOW_DAYS = 30;

    public function __construct(
        private readonly LoyaltyAccounts $accounts,
        private readonly LoyaltyLedger $ledger,
        private readonly PointsExpiry $expiry,
        private readonly EarningRules $rules,
        private readonly AfterCommit $afterCommit,
    ) {}

    public function name(): string
    {
        return 'loyalty';
    }

    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        $moment = $this->rules->capture();

        $this->afterCommit->run('loyalty.earn_on_payment', function () use ($event, $moment): void {
            $uuid = Payment::query()->whereKey($event->paymentId)->value('uuid');

            $this->rules->record($moment, PointsSource::Payment, is_string($uuid) ? $uuid : null);
            $this->syncPayment($event->paymentId);
        });
    }

    public function handleRefundSucceeded(RefundSucceeded $event): void
    {
        $moment = $this->rules->capture();

        $this->afterCommit->run('loyalty.reverse_on_refund', function () use ($event, $moment): void {
            $uuid = Refund::query()->whereKey($event->refundId)->value('uuid');

            $this->rules->record($moment, PointsSource::Refund, is_string($uuid) ? $uuid : null);
            $this->syncRefund($event->refundId);
        });
    }

    public function handleJourneyCompleted(JourneyCompleted $event): void
    {
        $moment = $this->rules->capture();

        $this->afterCommit->run('loyalty.earn_on_visit', function () use ($event, $moment): void {
            $uuid = ServiceJourney::query()->whereKey($event->journeyId)->value('uuid');

            $this->rules->record($moment, PointsSource::Journey, is_string($uuid) ? $uuid : null);
            $this->syncJourney($event->journeyId);
        });
    }

    /**
     * Brings the payment's invoice up to date. Returns how many rows it wrote.
     */
    public function syncPayment(int $paymentId, ?CarbonImmutable $now = null): int
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()->with('invoice')->find($paymentId);
        $invoice = $payment?->invoice;

        return $invoice instanceof Invoice ? $this->syncInvoice($invoice, $now) : 0;
    }

    /**
     * The same, from a refund.
     */
    public function syncRefund(int $refundId, ?CarbonImmutable $now = null): int
    {
        /** @var Refund|null $refund */
        $refund = Refund::query()->with('payment.invoice')->find($refundId);
        $invoice = $refund?->payment?->invoice;

        return $invoice instanceof Invoice ? $this->syncInvoice($invoice, $now) : 0;
    }

    /**
     * Replays one invoice's money and writes whatever loyalty row is missing.
     */
    public function syncInvoice(Invoice $invoice, ?CarbonImmutable $now = null): int
    {
        $customerId = $this->customerOf($invoice);

        if ($customerId === null) {
            return 0;
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var int $wrote */
        $wrote = DB::connection('tenant')->transaction(function () use ($invoice, $customerId, $at): int {
            $account = $this->accounts->lockFor($customerId);
            $events = $this->moneyEvents($invoice);

            if ($events === []) {
                return 0;
            }

            $rule = $this->invoiceRule($invoice, $account, $events[0]);

            if (! $rule instanceof LoyaltyInvoiceRule) {
                return 0;
            }

            $net = 0;
            $earned = 0;
            $wrote = 0;

            foreach ($events as $event) {
                $net += $event['kind'] === PointsKind::Earn ? $event['amount_minor'] : -$event['amount_minor'];
                $target = $rule->target(max(0, $net));
                $delta = $target - $earned;
                $earned = $target;

                if ($delta === 0 || $this->exists($event['source'], $event['source_uuid'], $event['kind'])) {
                    continue;
                }

                $wrote += $event['kind'] === PointsKind::Earn
                    ? $this->earn($account, $invoice, $event, $delta)
                    : $this->reverse($account, $invoice, $event, -$delta, $at);
            }

            return $wrote;
        });

        return $wrote;
    }

    /**
     * The visit reward for a completed visit with a performed service, under
     * the rule in force when the visit was completed. Returns 1 if it wrote.
     */
    public function syncJourney(int $journeyId, ?CarbonImmutable $now = null): int
    {
        /** @var ServiceJourney|null $journey */
        $journey = ServiceJourney::query()->with(['stages', 'appointment'])->find($journeyId);

        if (! $journey instanceof ServiceJourney || $journey->status !== JourneyStatus::Completed || $journey->completed_at === null) {
            return 0;
        }

        $performed = $journey->stages->contains(static fn ($stage): bool => $stage->status === StageStatus::Completed);

        if (! $performed || $journey->customerId() === 0) {
            return 0;
        }

        $completedAt = CarbonImmutable::instance($journey->completed_at)->utc();
        $state = $this->rules->at($completedAt, PointsSource::Journey, $journey->uuid);

        if (! $state->earnsOnVisits() || $state->version === null) {
            return 0;
        }

        /** @var int $wrote */
        $wrote = DB::connection('tenant')->transaction(function () use ($journey, $state, $completedAt): int {
            $account = $this->accounts->lockFor($journey->customerId());

            if ($this->exists(PointsSource::Journey, $journey->uuid, PointsKind::Earn)) {
                return 0;
            }

            $this->ledger->append($account, PointsKind::Earn, PointsDirection::In, $state->version->visit_points,
                PointsSource::Journey, $journey->uuid, $completedAt,
                reason: 'Visit completed',
                actor: Actor::system('loyalty'),
                expiresAt: $state->expiryFor($completedAt),
            );

            return 1;
        });

        return $wrote;
    }

    public function reconcile(CarbonImmutable $since): int
    {
        // One point on the timeline for every run, so an event whose own
        // observation never happened can still be placed (§6).
        $this->rules->record($this->rules->capture());

        return $this->replay($since, null);
    }

    /**
     * Called ONLY by Actions about to change a customer's balance (redeem,
     * adjust), before they lock and decide. Never from a read path.
     */
    public function reconcileCustomer(int $customerId, ?CarbonImmutable $since = null): int
    {
        return $this->replay($since ?? CarbonImmutable::now()->utc()->subDays(self::CUSTOMER_WINDOW_DAYS), $customerId);
    }

    private function replay(CarbonImmutable $since, ?int $customerId): int
    {
        $repaired = 0;

        $invoices = Invoice::query()->select('invoices.id')
            ->join('sales', 'sales.id', '=', 'invoices.sale_id')
            ->whereNotNull('sales.customer_id');

        if ($customerId !== null) {
            $invoices->where('sales.customer_id', $customerId);
        }

        $unearned = Payment::query()
            ->where('status', PaymentStatus::Succeeded->value)
            ->where('succeeded_at', '>=', $since)
            ->whereIn('invoice_id', $invoices)
            ->whereNotIn('uuid', LoyaltyTransaction::query()->select('source_uuid')->where('source_type', PointsSource::Payment->value))
            ->orderBy('succeeded_at')->orderBy('id')
            ->limit(1000)
            ->pluck('invoice_id');

        $unreversed = Refund::query()
            ->where('status', RefundStatus::Succeeded->value)
            ->where('succeeded_at', '>=', $since)
            ->whereNotIn('uuid', LoyaltyTransaction::query()->select('source_uuid')->where('source_type', PointsSource::Refund->value))
            ->whereIn('payment_id', Payment::query()->select('id')->whereIn('invoice_id', $invoices))
            ->orderBy('succeeded_at')->orderBy('id')
            ->limit(1000)
            ->pluck('payment_id');

        $fromRefunds = $unreversed->isEmpty() ? [] : Payment::query()->whereIn('id', $unreversed)->pluck('invoice_id')->all();

        foreach (array_values(array_unique([...$unearned->all(), ...$fromRefunds])) as $invoiceId) {
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->find($invoiceId);

            if ($invoice instanceof Invoice) {
                $repaired += $this->syncInvoice($invoice);
            }
        }

        $journeys = ServiceJourney::query()
            ->where('status', JourneyStatus::Completed->value)
            ->where('completed_at', '>=', $since)
            ->whereNotIn('uuid', LoyaltyTransaction::query()->select('source_uuid')->where('source_type', PointsSource::Journey->value));

        if ($customerId !== null) {
            $journeys->where(static function ($query) use ($customerId): void {
                $query->where('customer_id', $customerId)
                    ->orWhereIn('appointment_id', Appointment::query()->select('id')->where('customer_id', $customerId));
            });
        }

        foreach ($journeys->orderBy('completed_at')->orderBy('id')->limit(1000)->pluck('id') as $journeyId) {
            $repaired += $this->syncJourney((int) $journeyId);
        }

        return $repaired;
    }

    /**
     * An invoice's money, in the order it happened: every successful payment
     * that EARNS (the center owned `loyalty` and a spend rule was in force when
     * it succeeded), and every successful refund of those payments. Money
     * collected while nothing earned is left out entirely — and so are its
     * refunds, which have nothing to take back.
     *
     * @return list<array{kind: PointsKind, source: PointsSource, source_uuid: string, at: CarbonImmutable, amount_minor: int, state: EarningState}>
     */
    private function moneyEvents(Invoice $invoice): array
    {
        /** @var list<Payment> $payments */
        $payments = Payment::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereNotNull('succeeded_at')
            ->orderBy('succeeded_at')->orderBy('id')
            ->get()
            ->all();

        $events = [];
        $earning = [];

        foreach ($payments as $payment) {
            $at = CarbonImmutable::instance($payment->succeeded_at)->utc();
            $state = $this->rules->at($at, PointsSource::Payment, $payment->uuid);

            if (! $state->earnsOnSpend()) {
                continue;
            }

            $earning[] = (int) $payment->getKey();

            $events[] = [
                'kind' => PointsKind::Earn,
                'source' => PointsSource::Payment,
                'source_uuid' => $payment->uuid,
                'at' => $at,
                'amount_minor' => $payment->amount_minor,
                'state' => $state,
            ];
        }

        if ($earning === []) {
            return [];
        }

        /** @var list<Refund> $refunds */
        $refunds = Refund::query()
            ->whereIn('payment_id', $earning)
            ->where('status', RefundStatus::Succeeded->value)
            ->whereNotNull('succeeded_at')
            ->get()
            ->all();

        foreach ($refunds as $refund) {
            $at = CarbonImmutable::instance($refund->succeeded_at)->utc();

            $events[] = [
                'kind' => PointsKind::Reversal,
                'source' => PointsSource::Refund,
                'source_uuid' => $refund->uuid,
                'at' => $at,
                'amount_minor' => $refund->amount_minor,
                'state' => $this->rules->at($at, PointsSource::Refund, $refund->uuid),
            ];
        }

        usort($events, static fn (array $a, array $b): int => [$a['at'], $a['kind'] === PointsKind::Earn ? 0 : 1, $a['source_uuid']]
            <=> [$b['at'], $b['kind'] === PointsKind::Earn ? 0 : 1, $b['source_uuid']]);

        return $events;
    }

    /**
     * The rule this invoice earns under: the version effective when its first
     * earning payment succeeded, frozen so later events keep using it.
     *
     * @param  array{kind: PointsKind, source: PointsSource, source_uuid: string, at: CarbonImmutable, amount_minor: int, state: EarningState}  $first
     */
    private function invoiceRule(Invoice $invoice, LoyaltyAccount $account, array $first): ?LoyaltyInvoiceRule
    {
        /** @var LoyaltyInvoiceRule|null $rule */
        $rule = LoyaltyInvoiceRule::query()->where('invoice_uuid', $invoice->uuid)->first();

        if ($rule instanceof LoyaltyInvoiceRule) {
            return $rule;
        }

        if ($first['state']->version === null) {
            return null;
        }

        /** @var LoyaltyInvoiceRule $created */
        $created = LoyaltyInvoiceRule::query()->create([
            'invoice_uuid' => $invoice->uuid,
            'loyalty_account_id' => $account->getKey(),
            ...$first['state']->version->spendRule(),
            // The moment it froze: when this invoice first earned anything.
            'created_at' => $first['at'],
        ]);

        return $created;
    }

    /**
     * @param  array{kind: PointsKind, source: PointsSource, source_uuid: string, at: CarbonImmutable, amount_minor: int, state: EarningState}  $event
     */
    private function earn(LoyaltyAccount $account, Invoice $invoice, array $event, int $points): int
    {
        $this->ledger->append($account, PointsKind::Earn, PointsDirection::In, $points,
            PointsSource::Payment, $event['source_uuid'], $event['at'],
            contextUuid: $invoice->uuid,
            reason: 'Money collected',
            actor: Actor::system('loyalty'),
            expiresAt: $event['state']->expiryFor($event['at']),
        );

        return 1;
    }

    /**
     * @param  array{kind: PointsKind, source: PointsSource, source_uuid: string, at: CarbonImmutable, amount_minor: int, state: EarningState}  $event
     */
    private function reverse(LoyaltyAccount $account, Invoice $invoice, array $event, int $points, CarbonImmutable $at): int
    {
        // Aged-out points are written off first, so the balance is what can
        // really be taken, and never goes below zero.
        $this->expiry->apply($account, $at);
        $applied = min($points, $account->balance);

        $this->ledger->append($account, PointsKind::Reversal, PointsDirection::Out, $applied,
            PointsSource::Refund, $event['source_uuid'], $at,
            contextUuid: $invoice->uuid,
            reason: 'Money refunded',
            actor: Actor::system('loyalty'),
            unrecovered: $points - $applied,
        );

        return 1;
    }

    private function exists(PointsSource $source, string $uuid, PointsKind $kind): bool
    {
        return LoyaltyTransaction::query()
            ->where('source_type', $source->value)
            ->where('source_uuid', $uuid)
            ->where('kind', $kind->value)
            ->exists();
    }

    private function customerOf(Invoice $invoice): ?int
    {
        $customerId = Sale::query()->whereKey($invoice->sale_id)->value('customer_id');

        return $customerId === null ? null : (int) $customerId;
    }
}
