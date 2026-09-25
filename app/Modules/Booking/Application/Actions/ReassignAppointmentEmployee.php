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
use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Availability\EmployeeAssigner;
use App\Modules\Booking\Domain\Enums\EmployeeSelection;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Gives one booked service a different team member, at the same time.
 *
 * The decision docs/15 §17 leaves to the center — "we deactivated Ahmed, who
 * takes his Thursday?" — made through the engine's own rules rather than an
 * edit to a column (docs/15-BOOKING.md §§8, 17):
 *
 *  - the new person must be ACTIVE, CURRENTLY eligible for the service and
 *    assigned to the branch — the checks a new booking gets (§20);
 *  - "any available" picks the lowest eligible id who is free, exactly the
 *    policy a new booking uses (§8);
 *  - "free" is the AUTHORITATIVE answer: under the branch lock, inside the
 *    transaction, against appointments AND availability blocks, ignoring only
 *    this appointment — and then against the visit's OTHER items, so a
 *    parallel layout cannot give one person two overlapping services;
 *  - the time, the price, the duration and the rooms do not move. Only the
 *    reserved employee changes, and `employee_selection` records whether a
 *    person was named (a later reschedule then keeps them) or not.
 *
 * Audited with the same before/after {@see AppointmentSnapshot} a reschedule
 * uses. No customer contact, no note body.
 *
 * Refused for a finished, cancelled or missed appointment: history says who
 * was booked, and a closed visit's operational record is the journey's.
 */
final class ReassignAppointmentEmployee
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly EmployeeAssigner $assigner,
        private readonly BranchLock $lock,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  string|null  $employeeUuid  null means "any available"
     *
     * @throws AuthorizationException
     * @throws BookingFailed
     */
    public function __invoke(
        Appointment $appointment,
        string $itemUuid,
        ?string $employeeUuid,
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

        /** @var Branch $branch */
        $branch = $appointment->branch()->firstOrFail();

        /** @var AppointmentItem|null $item */
        $item = $appointment->items()->with('service')->where('uuid', $itemUuid)->first();

        if (! $item instanceof AppointmentItem) {
            throw BookingFailed::policy('That service is not part of this booking.');
        }

        $candidates = $this->candidates($item, $employeeUuid, $branch);

        $before = AppointmentSnapshot::of($appointment);

        DB::connection('tenant')->transaction(function () use ($appointment, $item, $candidates, $branch, $employeeUuid): void {
            $this->lock->acquireOne((int) $branch->getKey());

            // Re-read under the lock: a move or a cancellation that committed
            // a moment ago must not be answered from the window read before it.
            $item->refresh();

            if ($appointment->refresh()->isTerminal()) {
                throw BookingFailed::invalidTransition(
                    'A completed, cancelled or missed appointment cannot be changed.',
                    ['status' => $appointment->status->value],
                );
            }

            $window = new TimeWindow($item->starts_at->utc(), $item->ends_at->utc());
            $chosen = null;

            foreach ($candidates as $candidate) {
                if ($this->takenByAnotherItem($appointment, $item, $candidate, $window)) {
                    continue;
                }

                if ($this->assigner->isEmployeeFree($candidate, $window, (int) $branch->getKey(), (int) $appointment->getKey())) {
                    $chosen = $candidate;

                    break;
                }
            }

            if ($chosen === null) {
                throw BookingFailed::slotUnavailable(
                    $employeeUuid === null
                        ? 'No one is free for that service at that time.'
                        : 'That team member is not free at that time.'
                );
            }

            $item->forceFill([
                'employee_id' => $chosen,
                // Named by the desk: a later move keeps them, exactly as if the
                // customer had asked for them. "Any" stays any.
                'employee_selection' => $employeeUuid === null ? EmployeeSelection::Any : EmployeeSelection::Specific,
            ])->save();
        });

        $appointment->refresh()->load('items');

        $this->audit->record(new AuditEvent(
            action: 'booking.appointment.reassigned',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Appointment::class,
            targetId: $appointment->uuid,
            targetLabel: $appointment->customer()->value('name'),
            before: $before,
            after: AppointmentSnapshot::of($appointment),
            meta: ['item_uuid' => $item->uuid],
        ));

        return $appointment;
    }

    /**
     * Who may take the item, in the order to try them.
     *
     * @return list<int>
     *
     * @throws BookingFailed
     */
    private function candidates(AppointmentItem $item, ?string $employeeUuid, Branch $branch): array
    {
        $service = $item->service;

        if ($service === null) {
            // The service was deleted from the catalog; nobody can be checked
            // for eligibility against it, so nobody is offered.
            throw BookingFailed::policy('That service is no longer in the catalog, so its team member cannot be changed.');
        }

        $eligible = $this->assigner->eligibleIds($service, $branch);

        if ($employeeUuid === null) {
            if ($eligible === []) {
                throw BookingFailed::employeeUnavailable();
            }

            return $eligible;
        }

        $employee = Employee::query()->where('uuid', $employeeUuid)->first();

        // One refusal for "not active", "not eligible" and "not at this
        // branch": eligibleIds already asks all three, the way a new booking
        // would (§20).
        if (! $employee instanceof Employee || ! in_array((int) $employee->getKey(), $eligible, true)) {
            throw BookingFailed::employeeUnavailable('That team member cannot take this service at this branch.');
        }

        return [(int) $employee->getKey()];
    }

    /**
     * Does another item of the SAME visit already have this person at an
     * overlapping time? Only possible with a parallel layout, and the conflict
     * query cannot see it because it ignores this appointment as a whole.
     */
    private function takenByAnotherItem(Appointment $appointment, AppointmentItem $item, int $employeeId, TimeWindow $window): bool
    {
        return $appointment->items()
            ->where('id', '!=', $item->getKey())
            ->where('employee_id', $employeeId)
            ->where('starts_at', '<', $window->end)
            ->where('ends_at', '>', $window->start)
            ->exists();
    }
}
