<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Actions;

use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Privacy\Fingerprint;
use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Availability\BranchCalendar;
use App\Modules\Booking\Domain\Availability\EmployeeAssigner;
use App\Modules\Booking\Domain\Availability\LineResolver;
use App\Modules\Booking\Domain\Availability\ResourceAllocator;
use App\Modules\Booking\Domain\Availability\Scheduler;
use App\Modules\Booking\Domain\BookingSettings;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\ResolvedLine;
use App\Modules\Booking\Domain\Data\ResourceAssignment;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Resources\Domain\Models\ResourceType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates an appointment. THE booking write path, for every channel.
 *
 * ## The double-booking problem, and how this solves it
 *
 * "Check availability, then insert" is not safe. Two customers can both be told
 * a slot is free, and both inserts succeed — the check happened before either
 * write. The window is small and it is exactly the window a popular slot fills.
 *
 * So the authoritative check happens INSIDE a transaction, after taking a
 * pessimistic row lock on the branch:
 *
 *     BEGIN
 *       SELECT ... FROM branches WHERE id = ? FOR UPDATE   ← serialises here
 *       re-check every employee against the live table
 *       INSERT appointment + items + add-ons
 *     COMMIT
 *
 * **Why the branch row.** One row, so there is no lock-ordering deadlock to
 * reason about; it works when neither the employee nor the room is chosen yet
 * ("any available"), which a per-employee or per-resource lock cannot; and it
 * needs no Redis, no advisory-lock helper and nothing that behaves differently
 * between MariaDB and MySQL 8 (§9, §35).
 *
 * Phase 7 widened what that lock protects rather than replacing it. Resource
 * capacity is re-checked under it, and the mutations that CHANGE capacity —
 * editing a resource, blocking an employee's afternoon — now take the same lock
 * through {@see BranchLock}, so a booking and a capacity change can no longer
 * both succeed against a world that stopped being true (ADR-047).
 *
 * **The cost, stated plainly.** Booking creation at one branch is serialised.
 * That is milliseconds of work at a write rate measured in bookings per hour,
 * and it is the cheapest correct answer available. Narrowing it to per-employee
 * locks later changes this method only — the engine's contract does not move.
 *
 * ## Everything else this owns
 *
 * The entitlement gate lives HERE, not only in middleware, because the WhatsApp
 * bot, RAYAN and queued jobs never pass through HTTP middleware
 * (docs/05-ENTITLEMENTS.md §6.2). Authorization is by ACTOR: staff need
 * `appointment.create` and branch scope; a customer books only for themselves;
 * a guest is bound by the public rules the resolver already applied.
 */
final class CreateAppointment
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly AvailabilityEngine $availability,
        private readonly BranchCalendar $calendar,
        private readonly LineResolver $resolver,
        private readonly EmployeeAssigner $assigner,
        private readonly ResourceAllocator $resources,
        private readonly BranchLock $lock,
        private readonly Scheduler $scheduler,
        private readonly BookingSettings $settings,
        private readonly ResolveBookingCustomer $customers,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws BookingFailed
     * @throws AuthorizationException
     */
    public function __invoke(BookingRequest $request, BookingActor $actor, ?CarbonImmutable $now = null): Appointment
    {
        $this->entitlements->ensure('booking');

        $now ??= CarbonImmutable::now();

        $public = $actor->isGuest();
        $branch = $this->availability->branch($request->branchUuid, $public);

        $this->authorize($actor, $branch);

        $lines = $this->resolver->resolve($request->lines, $branch, $public);

        $startsAt = $request->startsAt->utc();

        $this->assertWithinPolicy($startsAt, $now);

        $windows = $this->scheduler->layout($lines, $startsAt);
        $span = $this->scheduler->union($windows);

        /*
         * EVERY ITEM has to sit inside one of the branch's opening intervals.
         *
         * Not the span. A layout with a deliberate gap — a colour at noon, the
         * haircut at four, either side of a siesta closure — has a span that
         * crosses a closed period and two items that are both perfectly valid.
         * Checking the span would refuse it (Phase 7 corrections §1).
         *
         * Checked before the lock because it cannot change under concurrency:
         * opening hours are not a contended resource.
         */
        foreach ($windows as $window) {
            if (! $this->calendar->coversWindow($branch, $window)) {
                throw BookingFailed::outsideHours();
            }
        }

        $customer = null;

        /** @var Appointment $appointment */
        $appointment = DB::connection('tenant')->transaction(
            function () use ($branch, $lines, $windows, $span, $request, $actor, &$customer): Appointment {
                // The serialisation point. Everything from here to COMMIT sees
                // a consistent view of this branch's book — including its
                // resources and its availability blocks.
                $this->lock->acquireOne((int) $branch->getKey());

                $assigned = $this->assignUnderLock($lines, $windows, $branch);

                // Rooms, chairs and devices, re-checked against the live
                // reservations with the lock held. Exactly one contender can
                // take the last unit of capacity (Phase 7 §§8, 9).
                $reservations = $this->resources->assignUnderLock($assigned, $windows, $branch);

                /*
                 * Customer resolution is INSIDE the transaction on purpose. A
                 * guest booking may create a customer, and a booking that then
                 * loses the slot under the lock would otherwise leave that
                 * record behind — a person in the center's CRM who was never
                 * served and never will be. Rolling back is the honest outcome.
                 */
                $customer = ($this->customers)($request->customer, $actor);

                return $this->persist(
                    $branch, $customer, $assigned, $windows, $reservations, $span, $request, $actor
                );
            }
        );

        if ($customer instanceof Customer) {
            $this->record($appointment, $customer, $actor);
        }

        return $appointment;
    }

    /**
     * Re-resolves every employee against the live table, with the lock held.
     *
     * This is the only check that counts. Availability computed a moment ago
     * was a snapshot; a specific employee may have been booked since, and an
     * "any available" line may have run out of free candidates.
     *
     * @param  list<ResolvedLine>  $lines
     * @param  list<TimeWindow>  $windows
     * @return list<ResolvedLine>
     *
     * @throws BookingFailed
     */
    private function assignUnderLock(array $lines, array $windows, Branch $branch): array
    {
        $assigned = [];

        // Employees taken by EARLIER lines of this same booking. Sequential
        // items never overlap, so this only matters if parallel services ever
        // arrive — but leaving it out would make that change silently wrong.
        $claimed = [];

        foreach ($lines as $position => $line) {
            $window = $windows[$position];

            $candidates = $line->employeeId !== null
                ? [$line->employeeId]
                : $this->assigner->eligibleIds($line->service, $branch);

            if ($candidates === []) {
                throw BookingFailed::employeeUnavailable(
                    'No one at that branch can perform one of the selected services.'
                );
            }

            $chosen = null;

            foreach ($candidates as $candidate) {
                if ($this->overlapsClaimed($candidate, $window, $claimed)) {
                    continue;
                }

                if ($this->assigner->isEmployeeFree($candidate, $window, (int) $branch->getKey())) {
                    $chosen = $candidate;

                    break;
                }
            }

            if ($chosen === null) {
                // Deliberately the same refusal whether a named employee is
                // busy or every eligible one is: telling a guest WHICH stylist
                // is occupied at 3pm is a readout of the shop's book.
                throw $line->requiresSpecificEmployee()
                    ? BookingFailed::slotUnavailable('That team member is no longer free at that time.')
                    : BookingFailed::slotUnavailable();
            }

            $claimed[$chosen][] = $window;
            $assigned[] = $line->assignedTo($chosen);
        }

        return $assigned;
    }

    /**
     * @param  array<int, list<TimeWindow>>  $claimed
     */
    private function overlapsClaimed(int $employeeId, TimeWindow $window, array $claimed): bool
    {
        foreach ($claimed[$employeeId] ?? [] as $taken) {
            if ($window->overlaps($taken)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes the appointment, its items and their add-ons.
     *
     * All inside the caller's transaction: a failed item write must not leave a
     * half-created appointment holding a slot nobody can see (§36).
     *
     * @param  list<ResolvedLine>  $lines
     * @param  list<TimeWindow>  $windows
     * @param  array<int, list<ResourceAssignment>>  $reservations
     */
    private function persist(
        Branch $branch,
        Customer $customer,
        array $lines,
        array $windows,
        array $reservations,
        TimeWindow $span,
        BookingRequest $request,
        BookingActor $actor,
    ): Appointment {
        /** @var Appointment $appointment */
        $appointment = Appointment::query()->create([
            'customer_id' => $customer->getKey(),
            'branch_id' => $branch->getKey(),
            'status' => AppointmentStatus::Booked,
            // From the ACTOR, never from the request body (§15).
            'source' => $actor->source,
            'starts_at' => $span->start,
            'ends_at' => $span->end,
            'booked_timezone' => $branch->timezone,
            'customer_note' => $request->customerNote,
            'created_by_type' => $actor->type->value,
            'created_by_id' => $actor->id,
            'created_by_label' => $actor->label,
        ]);

        foreach ($lines as $position => $line) {
            $window = $windows[$position];

            /** @var AppointmentItem $item */
            $item = $appointment->items()->create([
                'service_id' => $line->service->getKey(),
                'service_variation_id' => $line->variation?->getKey(),
                'employee_id' => $line->employeeId,
                'employee_selection' => $line->selection,
                'position' => $position,
                'starts_at' => $window->start,
                'ends_at' => $window->end,
                // The snapshots. From here on this is what the booking means.
                'duration_minutes' => $line->durationMinutes,
                'price_minor' => $line->priceMinor,
                'currency' => $line->currency->value,
                'service_name' => $line->service->name,
                'variation_name' => $line->variation?->name,
                'customer_note' => $line->note,
            ]);

            foreach ($line->addons as $index => $addon) {
                $item->addons()->create([
                    'service_addon_id' => $addon->getKey(),
                    'name' => $addon->name,
                    'price_minor' => $addon->price_minor,
                    'duration_minutes' => $addon->duration_minutes,
                    'currency' => $line->currency->value,
                    'sort_order' => $index,
                ]);
            }

            foreach ($reservations[$position] ?? [] as $assignment) {
                $resource = $assignment->resource;

                $type = $resource->type;

                $item->resourceReservations()->create([
                    'resource_id' => $resource->getKey(),
                    'quantity' => $assignment->quantity,
                    // Snapshotted for the same reason the price is: renaming
                    // "Room 1" to "VIP Room" must not rewrite what a customer
                    // was told last month (§36).
                    'resource_name' => $resource->name,
                    'resource_type_name' => $type instanceof ResourceType ? $type->name : $resource->name,
                ]);
            }
        }

        return $appointment;
    }

    /**
     * @throws AuthorizationException
     * @throws BookingFailed
     */
    private function authorize(BookingActor $actor, Branch $branch): void
    {
        if ($actor->isStaff()) {
            $user = $actor->user;

            if ($user === null || ! $user->hasPermission(Permission::AppointmentCreate)) {
                throw new AuthorizationException('You may not create appointments.');
            }

            // Permission alone is not authorization: a manager scoped to one
            // branch must not book into another (docs/06 §5).
            if (! $user->canAccessBranch((int) $branch->getKey())) {
                throw new AuthorizationException('You may not work in that branch.');
            }

            return;
        }

        if ($actor->isCustomer() && $actor->account?->canAuthenticate() !== true) {
            throw BookingFailed::policy('This account cannot make a booking.');
        }
    }

    /**
     * Lead time and horizon. The two rules Phase 6 actually has.
     *
     * @throws BookingFailed
     */
    private function assertWithinPolicy(CarbonImmutable $startsAt, CarbonImmutable $now): void
    {
        $earliest = $now->utc()->addMinutes($this->settings->minLeadMinutes());

        if ($startsAt < $earliest) {
            throw BookingFailed::policy('That time is too soon to book.');
        }

        if ($startsAt > $now->utc()->addDays($this->settings->maxAdvanceDays())) {
            throw BookingFailed::policy('That date is too far ahead to book.', [
                'max_advance_days' => $this->settings->maxAdvanceDays(),
            ]);
        }
    }

    /**
     * The audit entry.
     *
     * NO CUSTOMER PHONE, NO EMAIL. The name is kept as the target label — the
     * same deliberate exception Phase 5 made, because a trail of anonymous
     * uuids answers nothing during an investigation — and the phone appears
     * only as a keyed fingerprint (ADR-042, §32).
     */
    private function record(Appointment $appointment, Customer $customer, BookingActor $actor): void
    {
        $this->audit->record(new AuditEvent(
            action: 'booking.appointment.created',
            category: AuditCategory::Config,
            actor: $actor->toAuditActor(),
            targetType: Appointment::class,
            targetId: $appointment->uuid,
            targetLabel: $customer->name,
            after: AppointmentSnapshot::of($appointment),
            meta: [
                'customer_uuid' => $customer->uuid,
                'phone_fingerprint' => Fingerprint::of($customer->phone),
                'source' => $appointment->source->value,
            ],
        ));
    }
}
