<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;

/**
 * BOOKED · PERFORMED · CHARGED, side by side, for a sale that came from a visit.
 *
 * The checkout screen's reason to exist: three different facts that look alike
 * and must never be confused. A service can be booked and skipped, booked at one
 * price and charged at another, or performed without having been booked at all
 * — and staff need to SEE which, rather than assume the sale is the booking
 * (docs/18-SALES.md §44).
 *
 * Read-only. Three queries whatever the size of the visit.
 */
final class CheckoutReview
{
    /**
     * @return list<array{stage: string, service: string, booked: array{amount: int, currency: string, formatted: string}|null, performed: string, charged: array{amount: int, currency: string, formatted: string}|null, chargeable: bool}>
     */
    public function forSale(Sale $sale): array
    {
        if ($sale->service_journey_id === null) {
            return [];
        }

        $locale = app()->getLocale();

        /** @var list<JourneyStage> $stages */
        $stages = JourneyStage::query()
            ->where('service_journey_id', $sale->service_journey_id)
            ->with('item')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all();

        /** @var array<int, SaleItem> $charged */
        $charged = SaleItem::query()
            ->where('sale_id', $sale->getKey())
            ->whereNotNull('journey_stage_id')
            ->get()
            ->keyBy('journey_stage_id')
            ->all();

        $rows = [];

        foreach ($stages as $stage) {
            $item = $stage->item;
            $line = $charged[$stage->id] ?? null;

            $rows[] = [
                'stage' => $stage->uuid,
                'service' => $stage->serviceName()?->get($locale) ?? '',
                // A walk-in was never booked: it has no reservation to compare.
                'booked' => $item instanceof AppointmentItem
                    ? $sale->money((int) $item->price_minor)->toArray($locale)
                    : null,
                'performed' => $stage->status->value,
                'charged' => $line instanceof SaleItem ? $sale->money($line->line_subtotal_minor)->toArray($locale) : null,
                'chargeable' => $stage->status === StageStatus::Completed && ! $line instanceof SaleItem && $sale->isDraft(),
            ];
        }

        return $rows;
    }
}
