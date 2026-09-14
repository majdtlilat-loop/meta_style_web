<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;

/**
 * Keeps a visit's sale carrying every service the visit actually performed.
 *
 * A checkout can be opened while the visit is still in progress — the desk
 * previews the bill — and stages go on completing after that. Without this, a
 * service performed after the draft was prepared would simply be missing from
 * the invoice. So every COMPLETED stage without a line gets one: when checkout
 * is reopened, and authoritatively inside finalization, once the visit is
 * complete and its stages can no longer change (docs/18-SALES.md §7).
 *
 * That is only sound because a visit line is never deleted from a sale. Charging
 * less for a performed service is an explicit price override with a reason, or
 * a discount — visible on the bill, in the audit trail, and behind
 * `sale.adjust` — not a line that quietly disappears and would come back here.
 *
 * Reads the visit; writes only sales rows.
 */
final class VisitLines
{
    public function __construct(
        private readonly JourneyChargeCandidates $candidates,
        private readonly SaleLines $lines,
    ) {}

    /**
     * Whether any completed stage of the visit has no line on this sale. Two
     * cheap reads, so reopening an up-to-date checkout takes no lock.
     */
    public function hasMissing(Sale $sale, ServiceJourney $journey): bool
    {
        $completed = JourneyStage::query()
            ->where('service_journey_id', $journey->getKey())
            ->where('status', StageStatus::Completed->value)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_diff($completed, $this->chargedStageIds($sale)) !== [];
    }

    /**
     * Appends a line for every completed stage that has none. Runs inside the
     * sale mutation, with the sale locked; the caller recalculates.
     *
     * @return int how many lines were appended
     *
     * @throws SaleFailed
     */
    public function sync(Sale $locked, ServiceJourney $journey): int
    {
        $charged = $this->chargedStageIds($locked);
        $appended = 0;

        foreach ($this->candidates->forJourney($journey, $locked->currency) as $snapshot) {
            if (in_array($snapshot->journeyStageId, $charged, true)) {
                continue;
            }

            $this->lines->append($locked, $snapshot, 1);
            $appended++;
        }

        return $appended;
    }

    /**
     * @return list<int>
     */
    private function chargedStageIds(Sale $sale): array
    {
        return array_values(SaleItem::query()
            ->where('sale_id', $sale->getKey())
            ->whereNotNull('journey_stage_id')
            ->pluck('journey_stage_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }
}
