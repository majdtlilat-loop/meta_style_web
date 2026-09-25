<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Database\AfterCommit;
use App\Kernel\Reconciliation\Contracts\Reconciler;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Memberships\Domain\Enums\CustomerMembershipStatus;
use App\Modules\Memberships\Domain\Events\MembershipActivated;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\CustomerMembershipBenefit;
use App\Modules\Memberships\Domain\Models\MembershipPlan;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Domain\Enums\SettlementState;
use App\Modules\Payments\Domain\Events\PaymentSucceeded;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Events\SaleFinalized;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Turns a paid-for membership line into the customer's membership — after the
 * money commits, and repairable from the money.
 *
 * ## When
 *
 * When the invoice that sold it is SETTLED — its successful payments cover the
 * total — or was free (a zero total, at finalization). An unpaid or part-paid
 * invoice activates nothing (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §11).
 *
 * ## The term
 *
 * `duration_days` whole branch-local days, counting the day it starts, ending
 * at the start of a local day. A RENEWAL — a second membership of the same
 * plan bought while one is still running — starts when the running one ends,
 * never overlapping it; the customer never pays twice for the same days.
 *
 * ## Never able to undo real money
 *
 * `PaymentSucceeded` and `SaleFinalized` only SCHEDULE activation for after
 * their transaction commits (`AfterCommit`). A failure here is reported and can
 * never roll back the payment or turn its success into an error (§1).
 *
 * ## Idempotent, and replayable
 *
 * Activation runs in its own transaction under the SALE lock — the lock every
 * benefit path takes first — then the CUSTOMER row, so two renewals settling at
 * once still stack one after the other. `unique(customer_memberships.
 * sale_item_id)` backs it: one sale line becomes one membership, however often
 * it runs. What a lost callback left undone is found from the canonical facts
 * — a finalized, settled sale with a membership line and no membership — by
 * `reconcile()` (hourly) and `reconcileCustomer()` (immediately before a
 * membership benefit is used). Reads never activate: a query that writes is not
 * a query.
 *
 * A purchase settled after the center lost `memberships` still activates: the
 * customer paid (§22).
 */
final class ActivateMemberships implements Reconciler
{
    public const CUSTOMER_WINDOW_DAYS = 90;

    public function __construct(
        private readonly InvoiceSettlement $settlement,
        private readonly MembershipsAudit $audit,
        private readonly AfterCommit $afterCommit,
        private readonly Dispatcher $events,
    ) {}

    public function name(): string
    {
        return 'memberships';
    }

    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        $this->afterCommit->run('memberships.activate_on_payment', function () use ($event): void {
            $saleId = Invoice::query()
                ->whereKey(Payment::query()->whereKey($event->paymentId)->value('invoice_id'))
                ->value('sale_id');

            if ($saleId !== null) {
                $this->syncSale((int) $saleId);
            }
        });
    }

    public function handleSaleFinalized(SaleFinalized $event): void
    {
        $this->afterCommit->run('memberships.activate_on_finalize', fn () => $this->syncSale($event->saleId));
    }

    /**
     * Activates every membership line of a settled sale that has no membership
     * yet. Returns how many it activated.
     */
    public function syncSale(int $saleId, ?CarbonImmutable $now = null): int
    {
        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var int $activated */
        $activated = DB::connection('tenant')->transaction(function () use ($saleId, $at): int {
            /** @var Sale|null $sale */
            $sale = Sale::query()->whereKey($saleId)->lockForUpdate()->first();

            if (! $sale instanceof Sale || $sale->status !== SaleStatus::Finalized || $sale->customer_id === null) {
                return 0;
            }

            /** @var list<SaleItem> $lines */
            $lines = SaleItem::query()
                ->where('sale_id', $sale->getKey())
                ->where('kind', SaleItemKind::Offering->value)
                ->where('offering_type', MembershipCatalog::TYPE)
                ->whereNotIn('id', CustomerMembership::query()->select('sale_item_id'))
                ->orderBy('position')
                ->get()
                ->all();

            if ($lines === []) {
                return 0;
            }

            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->where('sale_id', $sale->getKey())->first();

            if (! $invoice instanceof Invoice || ! $this->settled($invoice)) {
                return 0;
            }

            // The second lock: renewals of one customer stack in order.
            Customer::query()->whereKey($sale->customer_id)->lockForUpdate()->first();

            $count = 0;

            foreach ($lines as $line) {
                $count += $this->activate($sale, $line, $at);
            }

            return $count;
        });

        return $activated;
    }

    public function reconcile(CarbonImmutable $since): int
    {
        return $this->replay($since, null);
    }

    /**
     * Called ONLY by Actions about to use a customer's membership benefits:
     * anything they paid for that a lost callback left unactivated is activated
     * first. Never from a read path.
     */
    public function reconcileCustomer(int $customerId, ?CarbonImmutable $since = null): int
    {
        return $this->replay($since ?? CarbonImmutable::now()->utc()->subDays(self::CUSTOMER_WINDOW_DAYS), $customerId);
    }

    private function replay(CarbonImmutable $since, ?int $customerId): int
    {
        $sales = Sale::query()
            ->where('status', SaleStatus::Finalized->value)
            ->where('finalized_at', '>=', $since)
            ->whereNotNull('customer_id')
            ->whereIn('id', SaleItem::query()->select('sale_id')
                ->where('kind', SaleItemKind::Offering->value)
                ->where('offering_type', MembershipCatalog::TYPE)
                ->whereNotIn('id', CustomerMembership::query()->select('sale_item_id')));

        if ($customerId !== null) {
            $sales->where('customer_id', $customerId);
        }

        $repaired = 0;

        foreach ($sales->orderBy('finalized_at')->orderBy('id')->limit(1000)->pluck('id') as $saleId) {
            $repaired += $this->syncSale((int) $saleId);
        }

        return $repaired;
    }

    private function settled(Invoice $invoice): bool
    {
        return $invoice->grand_total_minor === 0
            || $this->settlement->forInvoice($invoice)->state === SettlementState::Paid;
    }

    private function activate(Sale $sale, SaleItem $line, CarbonImmutable $at): int
    {
        /** @var MembershipPlan|null $plan */
        $plan = MembershipPlan::query()
            ->where('uuid', (string) $line->offering_reference)
            ->with('benefits.service')
            ->first();

        if (! $plan instanceof MembershipPlan) {
            return 0;
        }

        $timezone = (string) (Branch::query()->whereKey($sale->branch_id)->value('timezone') ?: 'UTC');

        // A renewal starts when the running term of the same plan ends.
        $runningUntil = CustomerMembership::query()
            ->where('customer_id', $sale->customer_id)
            ->where('membership_plan_id', $plan->getKey())
            ->where('status', CustomerMembershipStatus::Active->value)
            ->where('expires_at', '>', $at)
            ->max('expires_at');

        $startsAt = $runningUntil === null ? $at : CarbonImmutable::parse((string) $runningUntil, 'UTC');

        if ($startsAt->lessThan($at)) {
            $startsAt = $at;
        }

        /** @var CustomerMembership $membership */
        $membership = CustomerMembership::query()->create([
            'customer_id' => $sale->customer_id,
            'membership_plan_id' => $plan->getKey(),
            'branch_id' => $sale->branch_id,
            'name' => $line->name,
            'price_minor' => $line->unit_price_minor,
            'currency' => $line->currency,
            'duration_days' => $plan->duration_days,
            'sale_id' => $sale->getKey(),
            'sale_item_id' => $line->getKey(),
            'activated_at' => $at,
            'starts_at' => $startsAt,
            'expires_at' => BranchClock::localDayStartAfter($startsAt, $plan->duration_days, $timezone),
            'status' => CustomerMembershipStatus::Active,
        ]);

        foreach ($plan->benefits as $benefit) {
            CustomerMembershipBenefit::query()->create([
                'customer_membership_id' => $membership->getKey(),
                'service_id' => $benefit->service_id,
                'service_name' => $benefit->service?->name,
                'discount_type' => $benefit->discount_type,
                'basis_points' => $benefit->basis_points,
                'amount_minor' => $benefit->amount_minor,
                'uses_limit' => $benefit->uses_per_term,
            ]);
        }

        $this->audit->record('membership.activated', Actor::system('memberships'), $membership, $membership->uuid,
            after: [
                'starts_at' => $membership->starts_at->toIso8601String(),
                'expires_at' => $membership->expires_at->toIso8601String(),
                'benefits' => $plan->benefits->count(),
                'renewal' => $runningUntil !== null,
            ],
            meta: ['sale' => $sale->uuid, 'plan' => $plan->uuid],
        );

        /*
         * Announced inside the activation transaction, identifiers only. This
         * activation is ALREADY after-commit work following settled money
         * (ADR-061); a listener that tells the customer schedules its own write
         * for after this commits, so nothing downstream can undo what they paid
         * for (docs/23-NOTIFICATIONS.md §11).
         */
        $this->events->dispatch(new MembershipActivated(
            (int) $membership->getKey(),
            (int) $sale->customer_id,
        ));

        return 1;
    }
}
