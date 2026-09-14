<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain;

use App\Kernel\Time\Occupancy;
use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Availability\ResourceAllocator;
use App\Modules\Booking\Domain\Availability\ResourceFinder;
use App\Modules\Booking\Domain\Data\ResourceAssignment;
use App\Modules\Booking\Domain\Data\ResourceRequirement;
use App\Modules\Booking\Domain\Models\ResourceReservation;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\JourneyStageResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opening and closing a stage's ACTUAL hold on a room, chair or device.
 *
 * ## Runtime capacity is actual occupancy PLUS committed booking capacity
 *
 * Two different things can stop a customer going into a room, and a check that
 * looks at only one of them is wrong in a way nobody notices until a booked
 * customer is turned away at the door:
 *
 *   1. somebody is PHYSICALLY IN IT. The previous service overran by twenty
 *      minutes and its usage row is still open, whatever the plan said.
 *   2. somebody has it PROMISED. Nobody is in the room at 10:10, but it is
 *      reserved from 10:30 and this service is expected to run until 10:40.
 *
 * Phase 7 shipped with only (1), and recorded the gap as a risk. This is that
 * gap closed: one combined peak-occupancy calculation over actual usages AND
 * external committed reservations, against the candidate window
 * (docs/16-JOURNEY-RESOURCES.md §15).
 *
 * A reservation is "committed" by exactly the rule Booking already uses —
 * {@see ResourceFinder::committedLoadsFor()} filters on the booking conflict
 * set, so a cancelled, completed or no-show appointment stops consuming
 * capacity the moment it changes status, and the reservation rows it leaves
 * behind are history rather than a hold. That rule is not restated here; a
 * second copy of it is a second thing to get wrong.
 *
 * ## What must NOT be counted twice
 *
 * The two sets describe the same world from two sides, so they overlap:
 *
 *   - **This stage's own booked reservation.** The visit reserved the room and
 *     is now walking into it. Counting the reservation and the usage would have
 *     the customer competing with themselves (Phase 7 corrections §5).
 *   - **Any OTHER item whose service has already started.** Once a stage leaves
 *     `waiting` its reality is the usage row; its reservation is a plan that
 *     has already been acted on. Counting both would refuse a second customer
 *     from a capacity-two room that genuinely has a place free — and, worse,
 *     would keep refusing after the first customer finished EARLY, because the
 *     reservation runs to its planned end and the room does not.
 *
 * Every other item's reservation is a real competitor and is counted in full,
 * including another item of the same visit: two services booked back to back in
 * one room compete with each other exactly as two customers would.
 *
 * ## The candidate window is an admission check, not a promise
 *
 * "Now until the booked duration is up", or on a swap the remainder of it. It
 * exists to catch the obvious collision with committed capacity and nothing
 * else — there is no scheduling policy here, no buffer, no turnaround.
 *
 * The ACTUAL record stays `assigned_at → released_at` and is free to disagree:
 * a service that overruns writes a longer interval than the window it was
 * admitted on, and that is the truth of what happened. The expected window is
 * never written down as a completion time.
 *
 * ## An open usage is assumed to continue
 *
 * A row with no `released_at` has no end yet. It is treated as running to the
 * end of whatever window is being asked about, which is the conservative
 * reading and the only safe one: treating "still in use" as zero-length would
 * hand the same chair to a second customer while somebody is sitting in it.
 */
final class StageResources
{
    public function __construct(
        private readonly Occupancy $occupancy,
        private readonly ResourceFinder $reservations,
        private readonly ResourceAllocator $allocator,
    ) {}

    /**
     * Which resources this stage should take when it starts, and how much.
     *
     * TWO PATHS, ONE POLICY.
     *
     * A booked stage already has an answer: the booking chose concrete rooms
     * and wrote them onto the item. It is re-checked here — the plan can have
     * gone stale while a previous customer overran — but never re-chosen, for
     * the same reason a reschedule keeps its machine: the confirmation named it
     * (Phase 7 §36).
     *
     * A walk-in reserved nothing, so it must choose now. It does that through
     * `ResourceAllocator::allocate()` — the SAME deterministic walk the Booking
     * Engine uses — handed the combined occupancy this class computes. So a
     * walk-in cannot take a room promised to a 10:30 booking, and there is no
     * second allocation algorithm to drift (ADR-050, docs/17-QUEUE.md §16).
     *
     * @return list<array{resource: OperationalResource, quantity: int}>
     *
     * @throws JourneyFailed
     */
    public function planFor(JourneyStage $stage, TimeWindow $window, ?Branch $branch): array
    {
        return $stage->isWalkIn()
            ? $this->allocateForWalkIn($stage, $window, $branch)
            : $this->reservedFor($stage, $window);
    }

    /**
     * The combined loads on one resource, in the shape the allocator wants.
     *
     * The same set {@see assertFits()} decides on, exposed so the allocator can
     * be driven by it.
     *
     * @return list<array{window: TimeWindow, quantity: int}>
     */
    public function loadsFor(int $resourceId, TimeWindow $window, JourneyStage $stage): array
    {
        return array_merge(
            $this->actualLoads($resourceId, $window, (int) $stage->getKey()),
            $this->committedLoads($resourceId, $window, $stage),
        );
    }

    /**
     * Refuses if the resource cannot take this much more during $window.
     *
     * CALL THIS UNDER THE BRANCH LOCK, inside the transaction that will open
     * the usage row. Everything it reads can change a millisecond later
     * otherwise, and a capacity check that raced a booking is a capacity check
     * that passed for no reason (ADR-047).
     *
     * @throws JourneyFailed
     */
    public function assertFits(
        OperationalResource $resource,
        int $quantity,
        TimeWindow $window,
        JourneyStage $stage,
    ): void {
        $resourceId = (int) $resource->getKey();

        $actual = $this->actualLoads($resourceId, $window, (int) $stage->getKey());
        $committed = $this->committedLoads($resourceId, $window, $stage);

        // ONE calculation, over both sets at once. The peak is the only
        // question capacity can answer — a sum gets it wrong — and two separate
        // checks would each pass on a room that is full between them.
        if ($this->occupancy->fits(array_merge($actual, $committed), $window, $resource->capacity, $quantity)) {
            return;
        }

        // Refused, and which half refused it is what the person at the desk
        // needs to hear. "Somebody is in there" and "it is promised to somebody
        // at half past" have completely different answers.
        throw JourneyFailed::resourceUnavailable(
            $this->occupancy->fits($actual, $window, $resource->capacity, $quantity)
                ? 'That resource is reserved for another booking at that time.'
                : 'That resource is already in use at this time.',
            ['resource' => $resource->uuid, 'capacity' => $resource->capacity],
        );
    }

    /**
     * Opens a usage: this stage is now holding this resource.
     */
    public function open(
        JourneyStage $stage,
        OperationalResource $resource,
        int $quantity,
        CarbonImmutable $at,
        ?int $userId,
    ): JourneyStageResource {
        /** @var JourneyStageResource $usage */
        $usage = JourneyStageResource::query()->create([
            'journey_stage_id' => $stage->getKey(),
            'resource_id' => $resource->getKey(),
            'quantity' => $quantity,
            'assigned_at' => $at->utc(),
            'assigned_by_user_id' => $userId,
        ]);

        return $usage;
    }

    /**
     * Closes every open usage a stage holds.
     *
     * A swap closes ONE and opens another; finishing a stage closes them all.
     * Nothing is ever deleted or overwritten, so the intervals remain readable
     * afterwards — which is the whole reason this is a table of usages rather
     * than a column on the stage (corrections §5).
     *
     * @return int rows closed
     */
    public function releaseAll(JourneyStage $stage, CarbonImmutable $at, ?string $reason = null): int
    {
        return JourneyStageResource::query()
            ->where('journey_stage_id', $stage->getKey())
            ->whereNull('released_at')
            ->update([
                'released_at' => $at->utc(),
                'release_reason' => $reason,
                'updated_at' => $at->utc(),
            ]);
    }

    /**
     * A booked stage's rooms: the ones the booking already reserved.
     *
     * @return list<array{resource: OperationalResource, quantity: int}>
     *
     * @throws JourneyFailed
     */
    private function reservedFor(JourneyStage $stage, TimeWindow $window): array
    {
        $plan = [];

        $reservations = ResourceReservation::query()
            ->with('resource')
            ->where('appointment_item_id', $stage->appointment_item_id)
            ->get();

        foreach ($reservations as $reservation) {
            $resource = $reservation->resource;

            if (! $resource instanceof OperationalResource) {
                continue;
            }

            $this->assertFits($resource, $reservation->quantity, $window, $stage);

            $plan[] = ['resource' => $resource, 'quantity' => $reservation->quantity];
        }

        return $plan;
    }

    /**
     * A walk-in's rooms: chosen now, against everything already committed.
     *
     * @return list<array{resource: OperationalResource, quantity: int}>
     *
     * @throws JourneyFailed
     */
    private function allocateForWalkIn(JourneyStage $stage, TimeWindow $window, ?Branch $branch): array
    {
        $serviceId = $stage->serviceId();

        if ($serviceId === null || ! $branch instanceof Branch) {
            return [];
        }

        $requirements = $this->requirementsFor($serviceId);

        if ($requirements === []) {
            // Most services need no room at all. A haircut needs a chair the
            // center does not model, and that is a legitimate answer.
            return [];
        }

        $assignments = $this->allocator->allocate(
            $requirements,
            // Never pinned: reception picks a service, not a machine. Choosing
            // one is a Phase 7 swap, after the stage has started.
            [],
            $window,
            $branch,
            fn (int $resourceId): array => $this->loadsFor($resourceId, $window, $stage),
        );

        if ($assignments === null) {
            /*
             * Refused — and which half refused it is what the person at the desk
             * needs to hear, exactly as on the booked path. Running the same
             * allocation against ACTUAL occupancy alone answers it: if that
             * would have found a room, the blocker is somebody's committed
             * booking rather than somebody standing in the room.
             *
             * Only on the failure path, so the ordinary case still costs one
             * pass.
             */
            $withoutCommitted = $this->allocator->allocate(
                $requirements,
                [],
                $window,
                $branch,
                fn (int $resourceId): array => $this->actualLoads($resourceId, $window, (int) $stage->getKey()),
            );

            throw JourneyFailed::resourceUnavailable(
                $withoutCommitted === null
                    ? 'That resource is already in use at this time.'
                    : 'That resource is reserved for another booking at that time.',
                ['service_id' => $serviceId],
            );
        }

        return array_map(
            static fn (ResourceAssignment $assignment): array => [
                'resource' => $assignment->resource,
                'quantity' => $assignment->quantity,
            ],
            $assignments,
        );
    }

    /**
     * What a service needs, read from the Resources module.
     *
     * Booking reads the same table through its `LineResolver` and turns it into
     * the same value object — this is the second reader of one rule, not a
     * second rule (ADR-049).
     *
     * @return list<ResourceRequirement>
     */
    private function requirementsFor(int $serviceId): array
    {
        /** @var list<ResourceRequirement> $requirements */
        $requirements = ServiceResourceRequirement::query()
            ->where('service_id', $serviceId)
            ->orderBy('resource_type_id')
            ->get()
            ->map(static fn (ServiceResourceRequirement $row): ResourceRequirement => new ResourceRequirement(
                (int) $row->resource_type_id,
                max(1, (int) $row->quantity),
            ))
            ->all();

        return $requirements;
    }

    /**
     * Every OTHER stage's open or overlapping hold on this resource.
     *
     * @return list<array{window: TimeWindow, quantity: int}>
     */
    private function actualLoads(int $resourceId, TimeWindow $window, ?int $excludeStageId): array
    {
        $query = DB::connection('tenant')
            ->table('journey_stage_resources')
            ->where('resource_id', $resourceId)
            ->where(function ($q) use ($window): void {
                // Still open, or closed but overlapping the window asked about.
                $q->whereNull('released_at')
                    ->orWhere(function ($closed) use ($window): void {
                        $closed->where('assigned_at', '<', $window->end)
                            ->where('released_at', '>', $window->start);
                    });
            });

        if ($excludeStageId !== null) {
            $query->where('journey_stage_id', '!=', $excludeStageId);
        }

        $loads = [];

        foreach ($query->get(['quantity', 'assigned_at', 'released_at']) as $row) {
            /** @var object{quantity: int|string, assigned_at: string, released_at: string|null} $row */
            $start = CarbonImmutable::parse($row->assigned_at, 'UTC');

            $end = $row->released_at === null
                ? $window->end
                : CarbonImmutable::parse($row->released_at, 'UTC');

            // An open usage that started after the window under test does not
            // extend backwards into it.
            if ($end <= $start || $start >= $window->end || $end <= $window->start) {
                continue;
            }

            $loads[] = [
                'window' => new TimeWindow($start, $end),
                'quantity' => (int) $row->quantity,
            ];
        }

        return $loads;
    }

    /**
     * Committed booking reservations that genuinely compete for this resource.
     *
     * Read through the Booking seam, then narrowed by the one thing Booking
     * cannot know: which of those reservations have already been acted on
     * operationally. See the class doc block for why both exclusions exist.
     *
     * @return list<array{window: TimeWindow, quantity: int}>
     */
    private function committedLoads(int $resourceId, TimeWindow $window, JourneyStage $stage): array
    {
        $rows = $this->reservations->committedLoadsFor($resourceId, $window);

        if ($rows === []) {
            return [];
        }

        $ownItemId = (int) $stage->appointment_item_id;

        /** @var array<int, true> $competing */
        $competing = [];

        foreach ($rows as $row) {
            if ($row['appointment_item_id'] !== $ownItemId) {
                $competing[$row['appointment_item_id']] = true;
            }
        }

        if ($competing === []) {
            return [];
        }

        /*
         * ONE query for the whole competing set, not one per reservation. The
         * set is bounded by how many bookings overlap a single service's
         * duration on a single resource, which is small by construction — and
         * the bound is what keeps a stage start from costing a query per
         * reservation on a busy day.
         */
        $started = $this->startedItemIds(array_keys($competing));

        $loads = [];

        foreach ($rows as $row) {
            $itemId = $row['appointment_item_id'];

            if ($itemId === $ownItemId || isset($started[$itemId])) {
                continue;
            }

            $loads[] = ['window' => $row['window'], 'quantity' => $row['quantity']];
        }

        return $loads;
    }

    /**
     * Of these booked items, which have a stage that is no longer `waiting`?
     *
     * Their capacity is accounted for by `journey_stage_resources` — held while
     * the service runs and released when it ends, whether that is early or
     * late. The reservation has done its job, and counting it again would count
     * the same customer twice.
     *
     * @param  list<int>  $itemIds
     * @return array<int, true>
     */
    private function startedItemIds(array $itemIds): array
    {
        /** @var list<int> $ids */
        $ids = JourneyStage::query()
            ->whereIn('appointment_item_id', $itemIds)
            ->where('status', '!=', StageStatus::Waiting->value)
            ->pluck('appointment_item_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_fill_keys($ids, true);
    }
}
