<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Reconciliation\Contracts\Reconciler;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Events\SaleBenefitReleased;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use App\Modules\Sales\Domain\SaleMutation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * A benefit on a draft is a HOLD, and a hold expires.
 *
 * Redeeming points, covering a line with a package session or using a member's
 * allowance all happen on a DRAFT — before anything is published. Withdrawing
 * the benefit, discarding the draft or voiding the sale gives it back. But a
 * draft nobody ever finishes is none of those: without this, an abandoned cart
 * would hold a customer's points, sessions or uses for ever
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §7).
 *
 * So: a benefit adjustment on a sale that is STILL A DRAFT, where neither the
 * benefit nor the sale has been touched for `HOLD_HOURS`, is released. The
 * adjustment is removed, the sale is re-priced to what it would cost without
 * it, and `SaleBenefitReleased` tells the granting module to give it back — in
 * one transaction, under the sale lock, exactly like a void.
 *
 * ## Everything is decided under the lock
 *
 * The query that finds expired holds runs unlocked, so by the time the lock is
 * taken a cashier may have come back to the draft, or finished it. Every
 * condition is therefore checked AGAIN from the locked rows — still a draft
 * (`SaleMutation`), still holding this benefit, still untouched for
 * `HOLD_HOURS` — and the release is abandoned if any of them has changed. A
 * finalization that wins the lock first leaves the sale finalized and the hold
 * consumed; a release that wins leaves the draft at full price. The two can
 * never both have the benefit (docs/18-SALES.md §§18–19).
 *
 * The draft itself is left alone: the cashier may still finish it, at full
 * price, or apply the benefit again if the customer still has it. Sales names
 * no benefit module here either.
 *
 * Runs hourly through `metastyle:reconcile`.
 */
final class ReleaseStaleBenefits implements Reconciler
{
    /**
     * How long a draft may hold a benefit without being touched. A day covers
     * a visit prepared in the morning and paid in the evening, and still
     * returns the points to the customer the next day.
     */
    public const HOLD_HOURS = 24;

    /** Never more than this in one run, so a backlog cannot stall the schedule. */
    public const MAX_PER_RUN = 500;

    public function __construct(
        private readonly SaleMutation $mutation,
        private readonly SalesAudit $audit,
        private readonly Dispatcher $events,
    ) {}

    public function name(): string
    {
        return 'sales.benefit_holds';
    }

    public function reconcile(CarbonImmutable $since): int
    {
        return $this->release();
    }

    /**
     * Releases every expired hold. Returns how many it released.
     */
    public function release(?CarbonImmutable $now = null): int
    {
        $at = ($now ?? CarbonImmutable::now())->utc();
        $cutoff = $at->subHours(self::HOLD_HOURS);

        /** @var list<SaleAdjustment> $held */
        $held = SaleAdjustment::query()
            ->where('type', AdjustmentType::BenefitDiscount->value)
            ->whereNotNull('source_type')
            ->whereNotNull('source_reference')
            ->where('created_at', '<', $cutoff)
            ->whereIn('sale_id', Sale::query()->select('id')
                ->where('status', SaleStatus::Draft->value)
                // Untouched: a draft somebody is still working on keeps its hold.
                ->where('updated_at', '<', $cutoff))
            ->orderBy('id')
            ->limit(self::MAX_PER_RUN)
            ->get()
            ->all();

        $released = 0;

        foreach ($held as $adjustment) {
            $released += $this->releaseOne($adjustment, $cutoff);
        }

        return $released;
    }

    private function releaseOne(SaleAdjustment $adjustment, CarbonImmutable $cutoff): int
    {
        /** @var Sale|null $sale */
        $sale = Sale::query()->whereKey($adjustment->sale_id)->first();

        if (! $sale instanceof Sale) {
            return 0;
        }

        try {
            [, $released] = $this->mutation->apply($sale, function (Sale $locked) use ($adjustment, $cutoff): bool {
                // `SaleMutation` has already refused a sale the locked row says
                // is no longer a draft. What is left is whether it is still
                // untouched — a cashier who came back to it between the query
                // and the lock keeps the benefit.
                if ($locked->updated_at === null || $locked->updated_at->greaterThanOrEqualTo($cutoff)) {
                    return false;
                }

                // Re-read under the sale lock, which is the mutex every writer
                // of this cart takes: withdrawn, given back by a void, or
                // already released by a run that overlapped this one.
                /** @var SaleAdjustment|null $fresh */
                $fresh = SaleAdjustment::query()->whereKey($adjustment->getKey())->first();

                if (! $fresh instanceof SaleAdjustment
                    || $fresh->type !== AdjustmentType::BenefitDiscount
                    || $fresh->source_type === null
                    || $fresh->source_reference === null
                    || $fresh->created_at === null
                    || $fresh->created_at->greaterThanOrEqualTo($cutoff)) {
                    return false;
                }

                // The granting module gives it back inside this transaction.
                $this->events->dispatch(new SaleBenefitReleased(
                    (int) $locked->getKey(),
                    $fresh->source_type,
                    $fresh->source_reference,
                ));

                $fresh->delete();

                $this->audit->recordSystem('sale.benefit_released', $locked,
                    after: ['source_type' => $fresh->source_type],
                    meta: ['benefit' => $fresh->uuid, 'held_since' => $fresh->created_at->toIso8601String()],
                    reason: 'The draft was not finished within '.self::HOLD_HOURS.' hours',
                );

                return true;
            });
        } catch (SaleFailed) {
            // Finished or voided between the query and the lock: not stale.
            return 0;
        }

        return $released ? 1 : 0;
    }
}
