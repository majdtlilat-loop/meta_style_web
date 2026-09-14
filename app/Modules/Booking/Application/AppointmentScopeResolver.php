<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Authorization\AppointmentScope;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Turns a staff user into "the whole branch" or "only their own work".
 *
 * The scope itself is a Kernel value object with no module imports; resolving
 * one needs the EMPLOYEES table, which is a business module — so the lookup
 * lives here and the answer travels as {@see AppointmentScope}
 * (docs/04-MODULE-BOUNDARIES.md §2, docs/13-ROADMAP.md Phase 7 §30).
 *
 * ## The rule
 *
 *   `appointment.view`      → everything in the branches they may see
 *   `appointment.view_own`  → rows where their linked employee is assigned
 *   neither                 → nothing
 *
 * The broad grant WINS when both are held. A manager who is also a stylist
 * should not lose the branch's book because somebody also gave them the narrow
 * permission — the narrower code is an alternative, not a restriction.
 *
 * ## A user with no employee record sees NOTHING
 *
 * Not everything. `appointment.view_own` with no linked employee is a user who
 * has no own work, and returning an unrestricted scope there would be a
 * privilege escalation one careless `?? null` wide.
 */
final class AppointmentScopeResolver
{
    /** @var array<int, int|null> */
    private array $employeeIds = [];

    public function forViewer(User $viewer): AppointmentScope
    {
        if ($viewer->hasPermission(Permission::AppointmentView)) {
            return AppointmentScope::all();
        }

        if (! $viewer->hasPermission(Permission::AppointmentViewOwn)) {
            return AppointmentScope::none();
        }

        $employeeId = $this->employeeIdFor($viewer);

        return $employeeId === null
            ? AppointmentScope::none()
            : AppointmentScope::ownEmployee($employeeId);
    }

    /**
     * The same question for the Journey board.
     *
     * A separate pair of permissions, because seeing the day's bookings and
     * seeing who is in the building are different jobs: a stylist needs their
     * own stages all shift and has no business in the reception view.
     */
    public function forJourneyViewer(User $viewer): AppointmentScope
    {
        if ($viewer->hasPermission(Permission::JourneyView)) {
            return AppointmentScope::all();
        }

        if (! $viewer->hasPermission(Permission::JourneyViewOwn)) {
            return AppointmentScope::none();
        }

        $employeeId = $this->employeeIdFor($viewer);

        return $employeeId === null
            ? AppointmentScope::none()
            : AppointmentScope::ownEmployee($employeeId);
    }

    /**
     * The employee record this login belongs to, if any.
     *
     * `Employee.user_id` is the seam Phase 6 named and deliberately left
     * unused. A staff login is not an employee — a receptionist has a login and
     * no service history, a junior stylist may have an employee record and no
     * login at all — so the link is nullable and this may return null.
     */
    public function employeeIdFor(User $viewer): ?int
    {
        $key = (int) $viewer->getKey();

        if (array_key_exists($key, $this->employeeIds)) {
            return $this->employeeIds[$key];
        }

        $id = DB::connection('tenant')
            ->table((new Employee)->getTable())
            ->where('user_id', $key)
            ->value('id');

        return $this->employeeIds[$key] = $id === null ? null : (int) $id;
    }
}
