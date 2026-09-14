<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Availability;

use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which resources are already spoken for, and by how much.
 *
 * The Phase 7 counterpart of {@see ConflictFinder}, and deliberately a separate
 * class: Phase 6 wrote down that resources would arrive "as an additional
 * finder alongside this one — not as extra branches inside it"
 * (docs/13-ROADMAP.md Phase 6 §39, Phase 7 §10).
 *
 * ## Loads, not conflicts
 *
 * An employee is a yes/no question — booked or free. A resource is not: a
 * hammam of capacity four is neither free nor busy, it is three-quarters full.
 * So this returns QUANTIFIED WINDOWS and lets `Kernel\Time\Occupancy` work out
 * the peak, because the peak is the only question capacity can answer and a sum
 * gets it wrong (see that class for the worked example).
 *
 * ## Times come from the item
 *
 * `resource_reservations` stores no window of its own, so every query here
 * joins through to `appointment_items` for the times and to `appointments` for
 * the status. Cancelled and no-show bookings release their resources the moment
 * they are cancelled, for the same reason they release their stylist (§7).
 */
final class ResourceFinder
{
    /**
     * Every reservation load against a set of resources across a date range.
     *
     * One query for the whole range and the whole candidate set. The per-slot
     * form is `slots × resources` round trips, which is what §40 exists to
     * prevent.
     *
     * @param  list<int>  $resourceIds
     * @return array<int, list<array{window: TimeWindow, quantity: int}>> resource id => loads
     */
    public function loads(
        array $resourceIds,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $ignoreAppointmentId = null,
    ): array {
        if ($resourceIds === []) {
            return [];
        }

        $rows = $this->baseQuery($ignoreAppointmentId)
            ->whereIn('resource_reservations.resource_id', $resourceIds)
            ->where('appointment_items.starts_at', '<', $to->utc())
            ->where('appointment_items.ends_at', '>', $from->utc())
            ->select([
                'resource_reservations.resource_id',
                'resource_reservations.quantity',
                'appointment_items.starts_at',
                'appointment_items.ends_at',
            ])
            ->orderBy('appointment_items.starts_at')
            ->get();

        $loads = [];

        foreach ($rows as $row) {
            /** @var object{resource_id: int|string, quantity: int|string, starts_at: string, ends_at: string} $row */
            $loads[(int) $row->resource_id][] = [
                'window' => new TimeWindow(
                    CarbonImmutable::parse($row->starts_at, 'UTC'),
                    CarbonImmutable::parse($row->ends_at, 'UTC'),
                ),
                'quantity' => (int) $row->quantity,
            ];
        }

        return $loads;
    }

    /**
     * The loads against ONE resource in ONE window, straight from the database.
     *
     * THE AUTHORITATIVE READ, taken inside the booking transaction with the
     * branch lock held. Everything the availability query computed is a
     * snapshot; a reservation committed since is exactly what this catches
     * (§8, Phase 6 §9).
     *
     * @return list<array{window: TimeWindow, quantity: int}>
     */
    public function loadsFor(int $resourceId, TimeWindow $window, ?int $ignoreAppointmentId = null): array
    {
        $rows = $this->baseQuery($ignoreAppointmentId)
            ->where('resource_reservations.resource_id', $resourceId)
            ->where('appointment_items.starts_at', '<', $window->end)
            ->where('appointment_items.ends_at', '>', $window->start)
            ->select([
                'resource_reservations.quantity',
                'appointment_items.starts_at',
                'appointment_items.ends_at',
            ])
            ->get();

        $loads = [];

        foreach ($rows as $row) {
            /** @var object{quantity: int|string, starts_at: string, ends_at: string} $row */
            $loads[] = [
                'window' => new TimeWindow(
                    CarbonImmutable::parse($row->starts_at, 'UTC'),
                    CarbonImmutable::parse($row->ends_at, 'UTC'),
                ),
                'quantity' => (int) $row->quantity,
            ];
        }

        return $loads;
    }

    /**
     * Committed reservation loads on ONE resource, tagged with the item holding them.
     *
     * THE SEAM THE JOURNEY MODULE READS. Starting a service, or swapping the
     * room it is using, has to know what is already promised: a reservation
     * from 10:30 is committed capacity at 10:10, even though nobody is
     * physically in the room yet. Journey may read Booking; Booking must never
     * learn Journey exists, so the query lives here and the caller decides what
     * to do with the rows (docs/16-JOURNEY-RESOURCES.md).
     *
     * Which reservations count is NOT re-decided by the caller: `baseQuery`
     * filters on {@see AppointmentStatus::blockingValues()}, the same set every
     * other conflict check uses. A cancelled or no-show appointment leaves its
     * reservation rows behind as history and must not consume capacity because
     * of it.
     *
     * The item id travels with each row because the caller has to exclude some
     * of them — its own item, and any item whose service has already started
     * and is therefore represented by an actual usage row instead. Returning an
     * anonymous quantity would make that impossible without a second query per
     * reservation.
     *
     * @return list<array{appointment_item_id: int, window: TimeWindow, quantity: int}>
     */
    public function committedLoadsFor(int $resourceId, TimeWindow $window): array
    {
        $rows = $this->baseQuery(null)
            ->where('resource_reservations.resource_id', $resourceId)
            // The overlap rule, strict on both sides, exactly as everywhere
            // else. Never `whereBetween` (CLAUDE.md).
            ->where('appointment_items.starts_at', '<', $window->end)
            ->where('appointment_items.ends_at', '>', $window->start)
            ->select([
                'resource_reservations.appointment_item_id',
                'resource_reservations.quantity',
                'appointment_items.starts_at',
                'appointment_items.ends_at',
            ])
            ->get();

        $loads = [];

        foreach ($rows as $row) {
            /** @var object{appointment_item_id: int|string, quantity: int|string, starts_at: string, ends_at: string} $row */
            $loads[] = [
                'appointment_item_id' => (int) $row->appointment_item_id,
                'window' => new TimeWindow(
                    CarbonImmutable::parse($row->starts_at, 'UTC'),
                    CarbonImmutable::parse($row->ends_at, 'UTC'),
                ),
                'quantity' => (int) $row->quantity,
            ];
        }

        return $loads;
    }

    /**
     * Future appointments holding a resource that is no longer bookable.
     *
     * The operational answer to "we retired Laser Machine 1, who is booked on
     * it?". A query, never an automatic reassignment: moving a customer to a
     * different machine is a decision the center makes (§12, and the same
     * reasoning as inactive employees in Phase 6 §21).
     *
     * @return list<int> appointment ids
     */
    public function appointmentsOnInactiveResources(CarbonImmutable $from): array
    {
        /** @var list<int> $ids */
        $ids = $this->baseQuery(null)
            ->join('resources', 'resources.id', '=', 'resource_reservations.resource_id')
            ->where('appointment_items.starts_at', '>=', $from->utc())
            ->where(function (Builder $query): void {
                $query->where('resources.is_active', false)
                    ->orWhereNotNull('resources.archived_at');
            })
            ->distinct()
            ->pluck('appointments.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * @return Builder
     */
    private function baseQuery(?int $ignoreAppointmentId): mixed
    {
        $query = DB::connection('tenant')
            ->table('resource_reservations')
            ->join(
                'appointment_items',
                'appointment_items.id',
                '=',
                'resource_reservations.appointment_item_id'
            )
            ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
            ->whereIn('appointments.status', AppointmentStatus::blockingValues());

        if ($ignoreAppointmentId !== null) {
            // A reschedule must not collide with the resources it is releasing.
            $query->where('appointments.id', '!=', $ignoreAppointmentId);
        }

        return $query;
    }
}
