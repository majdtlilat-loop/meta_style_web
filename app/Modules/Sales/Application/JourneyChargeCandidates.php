<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Booking\Domain\Models\AppointmentItemAddon;
use App\Modules\Sales\Domain\Data\LineSnapshot;
use App\Modules\Sales\Domain\Enums\PriceSource;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;

/**
 * What a visit suggests charging for. Read-only: nothing here writes Journey.
 *
 * ## Booked, performed, charged — three facts
 *
 *   BOOKED     the appointment item — what was reserved, at the quoted price
 *   PERFORMED  the journey stage — whether it actually happened
 *   CHARGED    the sale line — what the customer pays
 *
 * A stage becomes a CANDIDATE line only once it is COMPLETED. A skipped stage is
 * never charged automatically, and neither is one still waiting or in service:
 * billing every booked item merely because it was booked would charge people
 * for services they declined (docs/18-SALES.md §7).
 *
 * ## Priced from the visit, not from today's catalog
 *
 * A booked visit carries the price the customer was quoted when they booked; a
 * walk-in stage carries the price at the moment they walked in. Charging the
 * catalog's price as of checkout instead would silently reprice a reservation.
 * A cashier who needs a different price overrides it explicitly, with a reason.
 *
 * ## The appointment price includes its add-ons
 *
 * Booking stores `appointment_items.price_minor` as base-or-variation PLUS every
 * add-on, and each add-on again on its own row. So the line's unit price is the
 * item price minus the add-ons, and the add-ons are carried separately — which
 * is what lets the invoice show them as their own lines of text.
 */
final class JourneyChargeCandidates
{
    /**
     * Candidate lines for every COMPLETED stage, in visit order.
     *
     * @return list<LineSnapshot>
     *
     * @throws SaleFailed
     */
    public function forJourney(ServiceJourney $journey, string $currency): array
    {
        /** @var list<JourneyStage> $stages */
        $stages = $journey->stages()
            ->where('status', StageStatus::Completed->value)
            ->with(['item.addons'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all();

        return array_map(fn (JourneyStage $stage): LineSnapshot => $this->forStage($stage, $currency), $stages);
    }

    /**
     * @throws SaleFailed
     */
    public function forStage(JourneyStage $stage, string $currency): LineSnapshot
    {
        if ($stage->status !== StageStatus::Completed) {
            throw SaleFailed::policy('Only a completed service can be charged from the visit.', [
                'stage_status' => $stage->status->value,
            ]);
        }

        $item = $stage->item;

        if ($item instanceof AppointmentItem) {
            return $this->booked($stage, $item, $currency);
        }

        return $this->walkIn($stage, $currency);
    }

    private function booked(JourneyStage $stage, AppointmentItem $item, string $currency): LineSnapshot
    {
        $this->assertCurrency($item->currency, $currency);

        $addons = [];
        $addonsTotal = 0;

        foreach ($item->addons as $addon) {
            /** @var AppointmentItemAddon $addon */
            $this->assertCurrency($addon->currency, $currency);

            $addons[] = [
                'service_addon_id' => $addon->service_addon_id,
                'name' => $addon->name,
                'unit_price_minor' => (int) $addon->price_minor,
            ];

            $addonsTotal += (int) $addon->price_minor;
        }

        $unit = (int) $item->price_minor - $addonsTotal;

        if ($unit < 0) {
            // Booking wrote an item cheaper than its own add-ons. Guessing a
            // price to paper over it would be inventing money.
            throw SaleFailed::policy('That booked service has an inconsistent price and cannot be charged automatically.');
        }

        return new LineSnapshot(
            kind: SaleItemKind::Service,
            name: $item->service_name,
            unitPriceMinor: $unit,
            priceSource: PriceSource::Journey,
            currency: $currency,
            variationName: $item->variation_name,
            addons: $addons,
            serviceId: $item->service_id,
            serviceVariationId: $item->service_variation_id,
            journeyStageId: $stage->id,
            employeeId: $stage->employee_id,
        );
    }

    private function walkIn(JourneyStage $stage, string $currency): LineSnapshot
    {
        $name = $stage->service_name;

        if ($name === null || $stage->price_minor === null || $stage->currency === null) {
            throw SaleFailed::policy('That service has no price recorded on the visit and cannot be charged automatically.');
        }

        $this->assertCurrency($stage->currency, $currency);

        return new LineSnapshot(
            kind: SaleItemKind::Service,
            name: $name,
            unitPriceMinor: (int) $stage->price_minor,
            priceSource: PriceSource::Journey,
            currency: $currency,
            serviceId: $stage->service_id,
            journeyStageId: $stage->id,
            employeeId: $stage->employee_id,
        );
    }

    private function assertCurrency(string $recorded, string $currency): void
    {
        if ($recorded !== $currency) {
            // No FX in Phase 9: a visit quoted in dollars cannot quietly become
            // a dinar line (docs/18-SALES.md §40).
            throw SaleFailed::policy('That visit was priced in a different currency and cannot be charged automatically.', [
                'visit_currency' => $recorded,
                'sale_currency' => $currency,
            ]);
        }
    }
}
