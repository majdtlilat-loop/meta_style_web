<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Availability;

use App\Modules\Booking\Domain\Data\StoredLine;
use App\Modules\Booking\Domain\Enums\EmployeeSelection;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Booking\Domain\Models\ResourceReservation;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Resources\Domain\Models\OperationalResource;
use Carbon\CarbonImmutable;

/**
 * The shape of an existing visit, for working out where it could move.
 *
 * Reads exactly what `RescheduleAppointment` reads,
 * with the same rules, so the times a desk is offered are the times the move
 * will accept (docs/15-BOOKING.md §§4, 8):
 *
 *  - each item keeps its OFFSET from the visit's start and its STORED duration
 *    — a gap stays a gap, a parallel pair stays parallel, a price and a length
 *    agreed last week are not re-read from today's catalog;
 *  - a line the customer booked with a named person stays with that person;
 *  - a line booked as "any available" may land on anybody currently eligible,
 *    lowest id first — and moves unassigned when nobody is (§21);
 *  - rooms and devices already held are re-checked, never re-allocated.
 *
 * If the reschedule's rules change, this must change with them; the
 * consistency test in `MoveAvailabilityTest` books every slot offered here.
 */
final class StoredLayout
{
    public function __construct(private readonly EmployeeAssigner $assigner) {}

    /**
     * @return list<StoredLine>
     */
    public function of(Appointment $appointment, Branch $branch): array
    {
        $items = $appointment->relationLoaded('items')
            ? $appointment->items
            : $appointment->items()->with(['service', 'resourceReservations.resource'])->get();

        $origin = CarbonImmutable::parse($appointment->starts_at, 'UTC');
        $lines = [];

        foreach ($items as $item) {
            /** @var AppointmentItem $item */
            $offset = (int) $origin->diffInMinutes(CarbonImmutable::parse($item->starts_at, 'UTC'));

            $lines[] = new StoredLine(
                offsetMinutes: max(0, $offset),
                durationMinutes: $item->duration_minutes,
                candidates: $this->candidates($item, $branch),
                held: $this->held($item),
            );
        }

        return $lines;
    }

    /**
     * @return list<int>
     */
    private function candidates(AppointmentItem $item, Branch $branch): array
    {
        if ($item->employee_selection === EmployeeSelection::Specific && $item->employee_id !== null) {
            return [$item->employee_id];
        }

        $service = $item->service;

        if ($service === null) {
            return $item->employee_id === null ? [] : [$item->employee_id];
        }

        return $this->assigner->eligibleIds($service, $branch);
    }

    /**
     * @return list<array{resource: OperationalResource, quantity: int}>
     */
    private function held(AppointmentItem $item): array
    {
        $held = [];

        foreach ($item->resourceReservations as $reservation) {
            /** @var ResourceReservation $reservation */
            $resource = $reservation->resource;

            if ($resource === null) {
                continue;
            }

            $held[] = ['resource' => $resource, 'quantity' => $reservation->quantity];
        }

        return $held;
    }
}
