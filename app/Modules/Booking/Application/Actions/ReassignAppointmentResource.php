<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\Occupancy;
use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Availability\ResourceAllocator;
use App\Modules\Booking\Domain\Availability\ResourceFinder;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Booking\Domain\Models\ResourceReservation;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\Resources\Domain\Models\ResourceType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Gives one reserved room or device of a booked service to another of the
 * same kind — same service, same time, same price.
 *
 * The desk's answer to "Room 2 is being repainted, put her in Room 3". A
 * reservation is a snapshot in the same sense as the price (docs/15-BOOKING.md
 * §36), so nothing ever moves it silently; this is the explicit decision, and
 * it is made with the rules a new booking would face:
 *
 *  - the `booking` entitlement, `appointment.update` and the appointment's
 *    branch in scope; open appointments only;
 *  - the new resource must be one a NEW booking could take for that
 *    requirement: the same resource type, active, at the booking's branch
 *    ({@see ResourceAllocator::candidates()});
 *  - it must have room for the reserved quantity for the item's whole window,
 *    by the same Occupancy peak every allocation uses, read from the live
 *    reservations under the branch lock — so two desks cannot both hand out
 *    the last free room (§§8, 9);
 *  - a resource the same service already holds is refused rather than merged:
 *    one reservation row per resource per service, as allocation writes them.
 *
 * Only `resource_id` and its two name snapshots change. Audited with the
 * resource before and after; never a customer's contact details.
 *
 * Booking only: whether the service has STARTED is a journey fact the Booking
 * module may not know (ADR-049). A running visit's room is swapped on the visit
 * board, where the actual usage is recorded; screens hide this action there.
 */
final class ReassignAppointmentResource
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly ResourceAllocator $allocator,
        private readonly ResourceFinder $finder,
        private readonly Occupancy $occupancy,
        private readonly BranchLock $lock,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws BookingFailed
     */
    public function __invoke(
        Appointment $appointment,
        string $itemUuid,
        string $fromResourceUuid,
        string $toResourceUuid,
        User $actingUser,
    ): Appointment {
        $this->entitlements->ensure('booking');

        if (! $actingUser->hasPermission(Permission::AppointmentUpdate)) {
            throw new AuthorizationException('You may not change appointments.');
        }

        if (! $actingUser->canAccessBranch((int) $appointment->branch_id)) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        if ($appointment->isTerminal()) {
            throw BookingFailed::invalidTransition(
                'A completed, cancelled or missed appointment cannot be changed.',
                ['status' => $appointment->status->value],
            );
        }

        $branch = $appointment->branch()->firstOrFail();

        $item = $appointment->items()->where('uuid', $itemUuid)->first();

        if (! $item instanceof AppointmentItem) {
            throw BookingFailed::policy('That service is not part of this booking.');
        }

        $held = $this->heldBy($item);
        $reservation = $held[$fromResourceUuid] ?? null;

        if (! $reservation instanceof ResourceReservation || ! $reservation->resource instanceof OperationalResource) {
            throw BookingFailed::policy('That room or device is not part of this booking.');
        }

        $target = null;

        foreach ($this->allocator->candidates((int) $reservation->resource->resource_type_id, $branch) as $candidate) {
            if ($candidate->uuid === $toResourceUuid && ! isset($held[$toResourceUuid])) {
                $target = $candidate;

                break;
            }
        }

        if (! $target instanceof OperationalResource) {
            throw BookingFailed::policy('That room or device cannot be used for this service at this branch.');
        }

        $before = self::snapshot($item, $reservation);

        DB::connection('tenant')->transaction(function () use ($appointment, $item, $reservation, $target, $branch): void {
            $this->lock->acquireOne((int) $branch->getKey());

            // Re-read under the lock: a move or cancellation committed a moment
            // ago must not be answered from the window read before it.
            $item->refresh();

            if ($appointment->refresh()->isTerminal()) {
                throw BookingFailed::invalidTransition(
                    'A completed, cancelled or missed appointment cannot be changed.',
                    ['status' => $appointment->status->value],
                );
            }

            $window = new TimeWindow($item->starts_at->utc(), $item->ends_at->utc());

            // Every live reservation on the new resource in this window —
            // other bookings AND this visit's other services — since the item
            // does not hold it yet (refused above), nothing needs ignoring.
            $loads = $this->finder->loadsFor((int) $target->getKey(), $window);

            if (! $this->occupancy->fits($loads, $window, $target->capacity, $reservation->quantity)) {
                throw BookingFailed::slotUnavailable('That room or device is not free at that time.');
            }

            $type = $target->type;

            $reservation->forceFill([
                'resource_id' => $target->getKey(),
                // New snapshots: the customer is now being given THIS room,
                // under the name it has today (§36).
                'resource_name' => $target->name,
                'resource_type_name' => $type instanceof ResourceType ? $type->name : $target->name,
            ])->save();
        });

        $reservation->refresh()->load('resource');

        $this->audit->record(new AuditEvent(
            action: 'booking.appointment.resource_changed',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Appointment::class,
            targetId: $appointment->uuid,
            targetLabel: $appointment->customer()->value('name'),
            before: $before,
            after: self::snapshot($item, $reservation),
            meta: ['item_uuid' => $item->uuid],
        ));

        return $appointment->refresh();
    }

    /**
     * The item's reservations, by the uuid of the resource each one holds.
     *
     * @return array<string, ResourceReservation>
     */
    private function heldBy(AppointmentItem $item): array
    {
        $held = [];

        foreach ($item->resourceReservations()->with('resource')->get() as $reservation) {
            /** @var ResourceReservation $reservation */
            $resource = $reservation->resource;

            if ($resource instanceof OperationalResource) {
                $held[(string) $resource->uuid] = $reservation;
            }
        }

        return $held;
    }

    /**
     * @return array{item_uuid: string, resource_uuid: string|null, resource_name: string|null, quantity: int}
     */
    private static function snapshot(AppointmentItem $item, ResourceReservation $reservation): array
    {
        $resource = $reservation->resource;

        return [
            'item_uuid' => (string) $item->uuid,
            'resource_uuid' => $resource instanceof OperationalResource ? (string) $resource->uuid : null,
            'resource_name' => $reservation->resource_name->get(),
            'quantity' => $reservation->quantity,
        ];
    }
}
