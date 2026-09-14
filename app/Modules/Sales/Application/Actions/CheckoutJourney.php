<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Modules\Sales\Application\JourneyChargeCandidates;
use App\Modules\Sales\Application\SaleLines;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesAudit;
use App\Modules\Sales\Application\VisitLines;
use App\Modules\Sales\Domain\Enums\SaleSource;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\SaleMutation;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Checkout: turn a visit into a draft sale — or hand back the one it already has.
 *
 * ## Orchestration from above, never a Journey side effect
 *
 * `CompleteJourney` does not create sales and never will: a center without POS
 * must be able to complete visits, and Journey must not learn Sales exists. The
 * till calls THIS, which reads the visit and writes only sales rows
 * (docs/18-SALES.md §§32–33).
 *
 * ## One live sale per visit
 *
 *     read   SELECT ... FROM sales WHERE active_journey_id = ?      ← the usual repeat
 *     lock   SELECT ... FROM service_journeys WHERE id = ? FOR UPDATE
 *     re-check under the lock, then create
 *     backstop  unique(active_journey_id) — a race loses here and reads the winner
 *
 * A double-clicked "checkout" therefore returns the same draft, never two. Voiding
 * releases the visit so it can be charged correctly afterwards (§8).
 *
 * ## What becomes a line
 *
 * Every COMPLETED stage, priced from the visit's own snapshot. Skipped stages
 * and stages not yet performed are not charged — the checkout screen shows them
 * so a cashier can add one deliberately (§7).
 *
 * ## Opening checkout early is fine; publishing it early is not
 *
 * The draft may be prepared while the visit is still in progress, and reopening
 * it picks up every service completed since ({@see VisitLines}). It cannot be
 * FINALIZED until the visit is completed — `FinalizeSale` refuses, and adds any
 * service still missing at that point, so the invoice is the whole visit.
 */
final class CheckoutJourney
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly JourneyChargeCandidates $candidates,
        private readonly SaleLines $lines,
        private readonly SaleMutation $mutation,
        private readonly VisitLines $visitLines,
        private readonly SalesAudit $audit,
    ) {}

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function __invoke(ServiceJourney $journey, User $actingUser): Sale
    {
        $branchId = $journey->branchId();

        $this->access->ensure($actingUser, Permission::SaleCreate, $branchId, 'You may not check visits out.');

        $existing = $this->liveSaleFor($journey);

        if ($existing instanceof Sale) {
            return $this->refreshed($existing, $journey, $actingUser);
        }

        try {
            /** @var array{0: Sale, 1: bool} $outcome */
            $outcome = DB::connection('tenant')->transaction(function () use ($journey, $actingUser, $branchId): array {
                /** @var ServiceJourney|null $locked */
                $locked = ServiceJourney::query()->whereKey($journey->getKey())->lockForUpdate()->first();

                if (! $locked instanceof ServiceJourney) {
                    throw SaleFailed::policy('That visit no longer exists.');
                }

                $again = $this->liveSaleFor($locked);

                if ($again instanceof Sale) {
                    return [$again, false];
                }

                if ($locked->status === JourneyStatus::Aborted) {
                    throw SaleFailed::policy('That visit was abandoned and cannot be checked out.');
                }

                $currency = Currency::default()->value;
                $snapshots = $this->candidates->forJourney($locked, $currency);

                /** @var Sale $sale */
                $sale = Sale::query()->create([
                    'branch_id' => $branchId,
                    // Defaulted from the visit, and only ever COPIED: changing
                    // the sale's customer later leaves the visit alone (§39).
                    'customer_id' => $locked->customerId(),
                    'service_journey_id' => $locked->getKey(),
                    'active_journey_id' => $locked->getKey(),
                    'source' => $locked->isWalkIn() ? SaleSource::WalkInCheckout : SaleSource::JourneyCheckout,
                    'status' => SaleStatus::Draft,
                    'currency' => $currency,
                    'created_by_id' => $actingUser->uuid,
                    'created_by_label' => $actingUser->name,
                ]);

                foreach ($snapshots as $snapshot) {
                    $this->lines->append($sale, $snapshot, 1);
                }

                $this->mutation->recalculate($sale);

                return [$sale, true];
            });
        } catch (UniqueConstraintViolationException $e) {
            $winner = $this->liveSaleFor($journey);

            if (! $winner instanceof Sale) {
                throw $e;
            }

            return $winner;
        }

        [$sale, $created] = $outcome;

        if ($created) {
            $this->audit->record('sale.created', $actingUser, $sale, after: [
                'source' => $sale->source->value,
                'status' => SaleStatus::Draft->value,
                'line_count' => $sale->items()->count(),
                'grand_total_minor' => $sale->grand_total_minor,
            ], meta: ['journey' => $journey->uuid]);
        }

        return $sale;
    }

    /**
     * The visit's existing sale — a draft brought up to date with any service
     * performed since it was prepared. A finalized or voided sale is returned
     * exactly as it is.
     *
     * @throws SaleFailed
     */
    private function refreshed(Sale $sale, ServiceJourney $journey, User $actingUser): Sale
    {
        if (! $sale->isDraft() || ! $this->visitLines->hasMissing($sale, $journey)) {
            return $sale;
        }

        try {
            /** @var array{0: Sale, 1: int} $outcome */
            $outcome = $this->mutation->apply($sale, fn (Sale $locked): int => $this->visitLines->sync($locked, $journey));
        } catch (SaleFailed $e) {
            // Finalized by another desk between the read and the lock: that is
            // the visit's sale now, and it is not ours to change.
            $current = Sale::query()->whereKey($sale->getKey())->first();

            if ($current instanceof Sale && ! $current->isDraft()) {
                return $current;
            }

            throw $e;
        }

        [$fresh, $added] = $outcome;

        if ($added > 0) {
            $this->audit->record('sale.visit_lines_added', $actingUser, $fresh,
                after: ['line_count' => $added, 'grand_total_minor' => $fresh->grand_total_minor],
                meta: ['journey' => $journey->uuid],
            );
        }

        return $fresh;
    }

    private function liveSaleFor(ServiceJourney $journey): ?Sale
    {
        /** @var Sale|null $sale */
        $sale = Sale::query()->where('active_journey_id', $journey->getKey())->first();

        return $sale;
    }
}
