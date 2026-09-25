<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Actions;

use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Availability\BranchCalendar;
use App\Modules\Booking\Domain\Availability\EmployeeAssigner;
use App\Modules\Booking\Domain\Availability\ResourceAllocator;
use App\Modules\Booking\Domain\Availability\Scheduler;
use App\Modules\Booking\Domain\BookingSettings;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Enums\EmployeeSelection;
use App\Modules\Booking\Domain\Events\AppointmentRescheduled;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Booking\Domain\Models\ResourceReservation;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Resources\Domain\Models\OperationalResource;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Moves an appointment to a new time.
 *
 * SAME ENGINE, SAME LOCK, SAME CHECKS AS CREATION. A reschedule that simply
 * wrote a new `starts_at` would be a double-booking path with no availability
 * check and no concurrency protection — the easiest way to undo everything
 * {@see CreateAppointment} does (docs/13-ROADMAP.md Phase 6 §25).
 *
 * ## What moves and what does not
 *
 * The whole visit shifts: every item is re-laid-out from the new start,
 * preserving order and durations. Employee assignments are re-resolved, and the
 * DISTINCTION IS PRESERVED — a line the customer booked with a named stylist
 * keeps that requirement and fails if they are busy at the new time, while a
 * line booked as "any available" is free to land on somebody else. Silently
 * moving a customer off the person they asked for would be the wrong kind of
 * helpful (§5).
 *
 * SNAPSHOTS DO NOT MOVE. Price and duration were agreed when the booking was
 * made and a change of time does not renegotiate them, so the items keep their
 * `price_minor` and `duration_minutes` even if the catalog has changed since
 * (§3). This is why the reschedule works from the stored items rather than
 * re-resolving the catalog.
 *
 * ONE APPOINTMENT, NOT TWO. No cancel-and-rebook: the uuid is stable, so a
 * customer's confirmation, a future reminder and the audit trail all keep
 * pointing at the same thing. The before/after is in the audit entry.
 *
 * ## Phase 7: layout and resources
 *
 * The RELATIVE POSITION of each item is preserved. A visit laid out as a colour
 * at the start and a haircut an hour later moves as that shape, not as two
 * back-to-back services — re-flattening the layout would silently delete the
 * development time somebody deliberately booked (§54).
 *
 * Rooms and devices are RE-VALIDATED, not re-allocated. The booking keeps the
 * concrete resources it holds and the move is refused if they are not free at
 * the new time. Re-running allocation would be free to move the customer to a
 * different machine without telling anybody (§35, §36).
 */
final class RescheduleAppointment
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly BranchCalendar $calendar,
        private readonly EmployeeAssigner $assigner,
        private readonly ResourceAllocator $resources,
        private readonly Scheduler $scheduler,
        private readonly BranchLock $lock,
        private readonly BookingSettings $settings,
        private readonly Audit $audit,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @throws BookingFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        Appointment $appointment,
        CarbonImmutable $startsAt,
        BookingActor $actor,
        ?CarbonImmutable $now = null,
    ): Appointment {
        $this->entitlements->ensure('booking');

        $now ??= CarbonImmutable::now();

        $this->authorize($appointment, $actor);

        if ($appointment->isTerminal()) {
            throw BookingFailed::invalidTransition(
                'A completed, cancelled or missed appointment cannot be moved.',
                ['status' => $appointment->status->value],
            );
        }

        $startsAt = $startsAt->utc();

        $this->assertWithinPolicy($startsAt, $now);

        /** @var Branch $branch */
        $branch = $appointment->branch()->firstOrFail();

        // `service` is eager-loaded because reassignment reads it per item.
        // Lazy-loading it would issue a query per service INSIDE the lock —
        // the one place in the product where an extra round trip is held
        // against every other booking at that branch.
        /*
         * `service` is eager-loaded because reassignment reads it per item, and
         * `resourceReservations.resource` because the resource re-check does.
         * Lazy-loading either would issue a query per item INSIDE the lock —
         * the one place in the product where an extra round trip is held
         * against every other booking at that branch.
         *
         * @var list<AppointmentItem> $items
         */
        $items = $appointment->items()
            ->with(['service', 'resourceReservations.resource'])
            ->get()
            ->all();

        if ($items === []) {
            throw BookingFailed::policy('That appointment has no services to move.');
        }

        $windows = $this->layout($items, $appointment, $startsAt);
        $span = $this->scheduler->union($windows);

        // Per item, never the span: a layout with a deliberate gap has a span
        // that may legitimately cross a closed interval (Phase 7 corrections §1).
        foreach ($windows as $window) {
            if (! $this->calendar->coversWindow($branch, $window)) {
                throw BookingFailed::outsideHours();
            }
        }

        $before = AppointmentSnapshot::of($appointment);

        DB::connection('tenant')->transaction(function () use ($appointment, $branch, $items, $windows, $span): void {
            // The same serialisation point creation uses, so a reschedule and a
            // new booking cannot both claim one slot (§9).
            $this->lock->acquireOne((int) $branch->getKey());

            $assigned = $this->reassign($items, $windows, $branch, (int) $appointment->getKey());

            // The rooms and devices this booking already holds, at the new
            // times. Refused rather than re-allocated (§35).
            $this->resources->revalidateUnderLock(
                $this->heldResources($items),
                $windows,
                (int) $appointment->getKey(),
            );

            foreach ($items as $position => $item) {
                $item->forceFill([
                    'starts_at' => $windows[$position]->start,
                    'ends_at' => $windows[$position]->end,
                    'employee_id' => $assigned[$position],
                ])->save();
            }

            $appointment->forceFill([
                'starts_at' => $span->start,
                'ends_at' => $span->end,
                // The zone is re-snapshotted: the customer is being told a NEW
                // wall-clock time, and it is the one in force now.
                'booked_timezone' => $branch->timezone,
            ])->save();

            // The customer was told an hour that is no longer the hour (§11).
            $this->events->dispatch(new AppointmentRescheduled(
                (int) $appointment->getKey(),
                (int) $appointment->branch_id,
                (int) $appointment->customer_id,
            ));
        });

        $appointment->refresh()->load('items');

        $this->audit->record(new AuditEvent(
            action: 'booking.appointment.rescheduled',
            category: AuditCategory::Config,
            actor: $actor->toAuditActor(),
            targetType: Appointment::class,
            targetId: $appointment->uuid,
            targetLabel: $appointment->customer()->value('name'),
            before: $before,
            after: AppointmentSnapshot::of($appointment),
        ));

        return $appointment;
    }

    /**
     * New windows from the STORED durations and the STORED shape.
     *
     * Each item keeps its offset from the start of the visit, so a gap stays a
     * gap and two parallel services stay parallel. Re-flattening to
     * back-to-back would quietly delete a development wait somebody booked on
     * purpose (§54).
     *
     * The durations come from the items, never from the catalog: price and
     * duration were agreed when the booking was made and moving it does not
     * renegotiate them (§3).
     *
     * @param  list<AppointmentItem>  $items
     * @return list<TimeWindow>
     */
    private function layout(array $items, Appointment $appointment, CarbonImmutable $startsAt): array
    {
        $origin = CarbonImmutable::parse($appointment->starts_at, 'UTC');
        $windows = [];

        foreach ($items as $item) {
            $offset = (int) $origin->diffInMinutes(CarbonImmutable::parse($item->starts_at, 'UTC'));

            $windows[] = TimeWindow::of($startsAt->addMinutes(max(0, $offset)), $item->duration_minutes);
        }

        return $windows;
    }

    /**
     * The concrete resources each item already holds.
     *
     * @param  list<AppointmentItem>  $items
     * @return array<int, list<array{resource: OperationalResource, quantity: int}>>
     */
    private function heldResources(array $items): array
    {
        $held = [];

        foreach ($items as $position => $item) {
            $held[$position] = [];

            foreach ($item->resourceReservations as $reservation) {
                /** @var ResourceReservation $reservation */
                $resource = $reservation->resource;

                if ($resource === null) {
                    continue;
                }

                $held[$position][] = ['resource' => $resource, 'quantity' => $reservation->quantity];
            }
        }

        return $held;
    }

    /**
     * Who takes each item at the new time.
     *
     * Run with the lock held, against the live table, ignoring THIS appointment
     * — otherwise moving a booking by fifteen minutes would conflict with the
     * copy of itself it is moving away from.
     *
     * @param  list<AppointmentItem>  $items
     * @param  list<TimeWindow>  $windows
     * @return array<int, int|null>
     *
     * @throws BookingFailed
     */
    private function reassign(array $items, array $windows, Branch $branch, int $appointmentId): array
    {
        $assigned = [];
        $claimed = [];

        foreach ($items as $position => $item) {
            $window = $windows[$position];

            $candidates = $this->candidates($item, $branch);

            $chosen = null;

            foreach ($candidates as $candidate) {
                if ($this->overlapsClaimed($candidate, $window, $claimed)) {
                    continue;
                }

                if ($this->assigner->isEmployeeFree($candidate, $window, (int) $branch->getKey(), $appointmentId)) {
                    $chosen = $candidate;

                    break;
                }
            }

            if ($chosen === null && $candidates !== []) {
                throw BookingFailed::slotUnavailable();
            }

            // An item whose employee was deleted, or whose service no longer
            // has an eligible employee at this branch, moves UNASSIGNED rather
            // than blocking the reschedule. The appointment is still real and
            // the customer is still coming; reception resolves the assignment
            // (§21).
            $assigned[$position] = $chosen;

            if ($chosen !== null) {
                $claimed[$chosen][] = $window;
            }
        }

        return $assigned;
    }

    /**
     * @return list<int>
     */
    private function candidates(AppointmentItem $item, Branch $branch): array
    {
        // A named stylist stays named. Re-opening the choice would move a
        // customer off the person they asked for without telling them.
        if ($item->employee_selection === EmployeeSelection::Specific && $item->employee_id !== null) {
            return [$item->employee_id];
        }

        $service = $item->service;

        if ($service === null) {
            // The service was hard-deleted. Keep whoever was assigned.
            return $item->employee_id === null ? [] : [$item->employee_id];
        }

        return $this->assigner->eligibleIds($service, $branch);
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
     * @throws AuthorizationException
     * @throws BookingFailed
     */
    private function authorize(Appointment $appointment, BookingActor $actor): void
    {
        if ($actor->isStaff()) {
            $user = $actor->user;

            if ($user === null || ! $user->hasPermission(Permission::AppointmentUpdate)) {
                throw new AuthorizationException('You may not change appointments.');
            }

            if (! $user->canAccessBranch((int) $appointment->branch_id)) {
                throw new AuthorizationException('You may not work in that branch.');
            }

            return;
        }

        if ($actor->isCustomer()) {
            // A customer may move their OWN booking and nobody else's. The
            // ownership check is here rather than in the controller so every
            // channel inherits it (§27).
            if ($actor->account?->customer_id !== $appointment->customer_id) {
                throw new AuthorizationException('That appointment is not yours.');
            }

            return;
        }

        // A guest has no way to identify themselves later, so there is nothing
        // to authorise. Rescheduling requires an account or a phone call.
        throw new AuthorizationException('You may not change appointments.');
    }

    /**
     * @throws BookingFailed
     */
    private function assertWithinPolicy(CarbonImmutable $startsAt, CarbonImmutable $now): void
    {
        if ($startsAt < $now->utc()->addMinutes($this->settings->minLeadMinutes())) {
            throw BookingFailed::policy('That time is too soon to book.');
        }

        if ($startsAt > $now->utc()->addDays($this->settings->maxAdvanceDays())) {
            throw BookingFailed::policy('That date is too far ahead to book.', [
                'max_advance_days' => $this->settings->maxAdvanceDays(),
            ]);
        }
    }
}
