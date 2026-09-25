<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Availability;

use App\Kernel\Time\Occupancy;
use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Data\ResolvedLine;
use App\Modules\Booking\Domain\Data\ResourceAssignment;
use App\Modules\Booking\Domain\Data\ResourceRequirement;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Resources\Domain\Models\OperationalResource;

/**
 * Decides WHICH chair, room or device each booked service takes.
 *
 * ## The strategy, in full
 *
 *   active resources of the required type, at this branch,
 *   in `sort_order` then `id` order,
 *   taking `min(remaining capacity, still needed)` from each
 *   until the requirement is satisfied — or refusing the whole line.
 *
 * That is the entire algorithm. No scoring, no "best room", no utilisation
 * balancing, no AI (docs/13-ROADMAP.md Phase 7 §11). Deterministic is the
 * property that matters: an idempotent retry must produce the same room as the
 * confirmation the customer already has, and a "smart" allocator would quietly
 * produce a different one.
 *
 * ## Capacity is a peak, not a sum
 *
 * Remaining capacity comes from `Kernel\Time\Occupancy`, which sweeps the
 * overlapping reservations rather than adding them up. Adding them up refuses
 * valid bookings — that class carries the worked example.
 *
 * ## A named resource is honoured or refused, never substituted
 *
 * When staff pin Laser Machine 2 and it is busy, the answer is no. Silently
 * moving the customer to Machine 1 would contradict whatever operational reason
 * the desk had for naming it (§11).
 *
 * ## Within one booking
 *
 * Lines of the same appointment are laid out in time, and with Phase 7's
 * optional offsets two of them can overlap. So resources claimed by earlier
 * lines are counted against later ones — otherwise a parallel pair would each
 * be told the last treatment room was free.
 */
final class ResourceAllocator
{
    /** @var array<string, list<OperationalResource>> */
    private array $byType = [];

    public function __construct(
        private readonly Occupancy $occupancy,
        private readonly ResourceFinder $finder,
    ) {}

    /**
     * Resources of one type that a new booking may take at this branch.
     *
     * Ordered, cached per request. Inactive and archived ones are absent, which
     * is what makes deactivation stop the NEXT booking without touching the
     * ones already made (§12).
     *
     * @return list<OperationalResource>
     */
    public function candidates(int $resourceTypeId, Branch $branch): array
    {
        $key = $resourceTypeId.':'.$branch->getKey();

        if (isset($this->byType[$key])) {
            return $this->byType[$key];
        }

        /** @var list<OperationalResource> $resources */
        $resources = OperationalResource::query()
            ->bookableAt((int) $branch->getKey())
            ->where('resource_type_id', $resourceTypeId)
            ->with('type')
            ->get()
            ->all();

        return $this->byType[$key] = $resources;
    }

    /**
     * Every resource that could be involved in these lines, for a bulk load.
     *
     * @param  list<ResolvedLine>  $lines
     * @return list<int>
     */
    public function candidateIds(array $lines, Branch $branch): array
    {
        $ids = [];

        foreach ($lines as $line) {
            foreach ($line->resourceRequirements as $requirement) {
                foreach ($this->candidates($requirement->resourceTypeId, $branch) as $resource) {
                    $ids[] = (int) $resource->getKey();
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * In-memory allocation across a whole multi-service booking.
     *
     * Used while generating slots, against a load map fetched once for the
     * date range. Returns null if any line cannot be resourced — a visit is one
     * arrival, and booking two of three services is worse than saying no (§4).
     *
     * @param  list<ResolvedLine>  $lines
     * @param  list<TimeWindow>  $windows  one per line, same order
     * @param  array<int, list<array{window: TimeWindow, quantity: int}>>  $loads
     * @return array<int, list<ResourceAssignment>>|null line position => assignments
     */
    public function assign(array $lines, array $windows, array $loads, Branch $branch): ?array
    {
        $assignments = [];

        foreach ($lines as $position => $line) {
            if ($line->resourceRequirements === []) {
                $assignments[$position] = [];

                continue;
            }

            $forLine = $this->allocate(
                $line->resourceRequirements,
                $line->pinnedResourceUuids,
                $windows[$position],
                $branch,
                fn (int $resourceId): array => $loads[$resourceId] ?? [],
            );

            if ($forLine === null) {
                return null;
            }

            $assignments[$position] = $forLine;

            // Claimed by this booking, so a parallel line does not take the
            // same last room.
            foreach ($forLine as $assignment) {
                $loads[(int) $assignment->resource->getKey()][] = [
                    'window' => $windows[$position],
                    'quantity' => $assignment->quantity,
                ];
            }
        }

        return $assignments;
    }

    /**
     * The authoritative allocation, inside the booking transaction.
     *
     * Reads the live tables per candidate resource with the branch lock held.
     * Everything the availability query decided was a snapshot; this is the
     * answer that counts, and it is the reason two customers cannot both take
     * the last treatment room (§8, §9).
     *
     * @param  list<ResolvedLine>  $lines
     * @param  list<TimeWindow>  $windows
     * @return array<int, list<ResourceAssignment>>
     *
     * @throws BookingFailed
     */
    public function assignUnderLock(
        array $lines,
        array $windows,
        Branch $branch,
        ?int $ignoreAppointmentId = null,
    ): array {
        $assignments = [];

        /** @var array<int, list<array{window: TimeWindow, quantity: int}>> $claimed */
        $claimed = [];

        foreach ($lines as $position => $line) {
            if ($line->resourceRequirements === []) {
                $assignments[$position] = [];

                continue;
            }

            $window = $windows[$position];

            $forLine = $this->allocate(
                $line->resourceRequirements,
                $line->pinnedResourceUuids,
                $window,
                $branch,
                fn (int $resourceId): array => array_merge(
                    $this->finder->loadsFor($resourceId, $window, $ignoreAppointmentId),
                    $claimed[$resourceId] ?? [],
                ),
            );

            if ($forLine === null) {
                // The same refusal whether a named resource is busy or every
                // room of that type is: telling a guest which of a center's
                // rooms is occupied at 3pm is a readout of its operations.
                throw BookingFailed::slotUnavailable(
                    $line->pinnedResourceUuids === []
                        ? 'That time is no longer available.'
                        : 'One of the resources you chose is no longer free at that time.'
                );
            }

            $assignments[$position] = $forLine;

            foreach ($forLine as $assignment) {
                $claimed[(int) $assignment->resource->getKey()][] = [
                    'window' => $window,
                    'quantity' => $assignment->quantity,
                ];
            }
        }

        return $assignments;
    }

    /**
     * Re-checks reservations a booking ALREADY holds, at new times.
     *
     * The reschedule path. The concrete resources do not change — a booking
     * that reserved Laser Machine 2 keeps Laser Machine 2, because the
     * reservation is a snapshot in the same sense the price is and re-running
     * allocation could silently move the customer to a different machine
     * (§36). What is re-checked is whether that machine has room at the NEW
     * time, ignoring the appointment being moved so it does not conflict with
     * the copy of itself it is moving away from.
     *
     * @param  array<int, list<array{resource: OperationalResource, quantity: int}>>  $held  position => held resources
     * @param  list<TimeWindow>  $windows
     *
     * @throws BookingFailed
     */
    public function revalidateUnderLock(array $held, array $windows, ?int $ignoreAppointmentId = null): void
    {
        /** @var array<int, list<array{window: TimeWindow, quantity: int}>> $claimed */
        $claimed = [];

        foreach ($held as $position => $reservations) {
            $window = $windows[$position];

            foreach ($reservations as $reservation) {
                $resource = $reservation['resource'];
                $id = (int) $resource->getKey();

                $loads = array_merge(
                    $this->finder->loadsFor($id, $window, $ignoreAppointmentId),
                    $claimed[$id] ?? [],
                );

                if (! $this->occupancy->fits($loads, $window, $resource->capacity, $reservation['quantity'])) {
                    throw BookingFailed::slotUnavailable(
                        'A room or device this booking uses is not free at that time.'
                    );
                }

                $claimed[$id][] = ['window' => $window, 'quantity' => $reservation['quantity']];
            }
        }
    }

    /**
     * Would the resources a booking ALREADY holds fit at new times?
     *
     * The in-memory counterpart of {@see revalidateUnderLock()}, for offering
     * move slots: the same Occupancy peak, the same per-resource capacity, the
     * same claim of earlier lines of the visit. The load map must already
     * exclude the appointment being moved. Advisory, like every slot; the
     * reschedule re-checks under the lock.
     *
     * @param  array<int, list<array{resource: OperationalResource, quantity: int}>>  $held  position => held resources
     * @param  list<TimeWindow>  $windows
     * @param  array<int, list<array{window: TimeWindow, quantity: int}>>  $loads
     */
    public function heldFit(array $held, array $windows, array $loads): bool
    {
        foreach ($held as $position => $reservations) {
            $window = $windows[$position];

            foreach ($reservations as $reservation) {
                $resource = $reservation['resource'];
                $id = (int) $resource->getKey();

                if (! $this->occupancy->fits($loads[$id] ?? [], $window, $resource->capacity, $reservation['quantity'])) {
                    return false;
                }

                $loads[$id][] = ['window' => $window, 'quantity' => $reservation['quantity']];
            }
        }

        return true;
    }

    /**
     * A set of requirements against one window: THE allocation policy.
     *
     * Public because it has a second caller. A walk-in stage reserved nothing
     * ahead of time, so when it starts it must choose rooms then and there —
     * against actual occupancy AND committed reservations rather than against
     * reservations alone (ADR-050, docs/17-QUEUE.md §16).
     *
     * That caller passes a different `$loadsFor`, and nothing else differs.
     * Re-implementing the walk in the Journey module would be a second answer to
     * "which room does this get", and the two would drift the first time either
     * changed: same order, same `min(remaining, needed)`, same refusal.
     *
     * An empty `$pinnedResourceUuids` means "any available". A non-empty one is
     * honoured or refused, never substituted.
     *
     * @param  list<ResourceRequirement>  $requirements
     * @param  list<string>  $pinnedResourceUuids
     * @param  callable(int): list<array{window: TimeWindow, quantity: int}>  $loadsFor
     * @return list<ResourceAssignment>|null
     */
    public function allocate(
        array $requirements,
        array $pinnedResourceUuids,
        TimeWindow $window,
        Branch $branch,
        callable $loadsFor,
    ): ?array {
        $assignments = [];

        // Within one line too: a service requiring two rooms must not be given
        // the same room twice.
        $taken = [];

        foreach ($requirements as $requirement) {
            $forType = $this->allocateRequirement(
                $requirement,
                $pinnedResourceUuids,
                $window,
                $branch,
                $loadsFor,
                $taken,
            );

            if ($forType === null) {
                return null;
            }

            foreach ($forType as $assignment) {
                $assignments[] = $assignment;

                $taken[(int) $assignment->resource->getKey()][] = [
                    'window' => $window,
                    'quantity' => $assignment->quantity,
                ];
            }
        }

        return $assignments;
    }

    /**
     * @param  list<string>  $pinnedResourceUuids
     * @param  callable(int): list<array{window: TimeWindow, quantity: int}>  $loadsFor
     * @param  array<int, list<array{window: TimeWindow, quantity: int}>>  $taken
     * @return list<ResourceAssignment>|null
     */
    private function allocateRequirement(
        ResourceRequirement $requirement,
        array $pinnedResourceUuids,
        TimeWindow $window,
        Branch $branch,
        callable $loadsFor,
        array $taken,
    ): ?array {
        $candidates = $this->candidates($requirement->resourceTypeId, $branch);

        // A caller that named specific resources gets those and no others. An
        // empty pin list for this type means "any available".
        $pinned = $this->pinnedOf($pinnedResourceUuids, $candidates);

        if ($pinned !== []) {
            $candidates = $pinned;
        }

        $needed = $requirement->quantity;
        $assignments = [];

        foreach ($candidates as $resource) {
            if ($needed <= 0) {
                break;
            }

            $id = (int) $resource->getKey();

            $remaining = $this->occupancy->remaining(
                array_merge($loadsFor($id), $taken[$id] ?? []),
                $window,
                $resource->capacity,
            );

            if ($remaining < 1) {
                continue;
            }

            $take = min($remaining, $needed);

            $assignments[] = new ResourceAssignment($resource, $take);
            $needed -= $take;
        }

        // Not enough of this type free for the whole window. The line — and
        // therefore the booking — is refused rather than partially resourced.
        return $needed > 0 ? null : $assignments;
    }

    /**
     * The pinned resources belonging to this requirement's type.
     *
     * @param  list<string>  $pinnedResourceUuids
     * @param  list<OperationalResource>  $candidates
     * @return list<OperationalResource>
     */
    private function pinnedOf(array $pinnedResourceUuids, array $candidates): array
    {
        if ($pinnedResourceUuids === []) {
            return [];
        }

        $pinned = [];

        foreach ($candidates as $resource) {
            if (in_array($resource->uuid, $pinnedResourceUuids, true)) {
                $pinned[] = $resource;
            }
        }

        return $pinned;
    }
}
