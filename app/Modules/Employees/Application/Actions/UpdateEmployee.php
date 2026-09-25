<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Employees\Application\StaffBranches;
use App\Modules\Employees\Application\StaffGuard;
use App\Modules\Employees\Domain\Data\EmployeeChanges;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames a member of staff and changes which branches they work at.
 *
 * Branch membership decides who the Booking Engine may assign
 * (`EmployeeAssigner` reads `employee_branches`), so a change takes the branch
 * lock of every branch gained or lost — the same serialisation point a booking
 * takes (ADR-047). Appointments already made are not touched.
 *
 * The actor must reach the person as they are now, and every branch added or
 * removed must be one the actor runs: a manager of Karrada cannot move a
 * Mansour stylist, nor pull one into Karrada from a branch they do not run.
 *
 * The linked login's display name follows the employee's, so the account menu
 * and the audit trail do not keep an old name alive.
 */
final class UpdateEmployee
{
    public function __construct(
        private readonly BranchLock $lock,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Employee $employee, EmployeeChanges $changes, User $actingUser): Employee
    {
        if (! $actingUser->hasPermission(Permission::StaffUpdate)) {
            throw new AuthorizationException(__('manager_staff.errors.update_denied'));
        }

        StaffGuard::assertReaches($actingUser, $employee);

        $name = TranslatedText::fromArray($changes->name);

        if ($name->isEmpty()) {
            throw ValidationException::withMessages(['name' => __('manager_staff.errors.name_required')]);
        }

        $current = $employee->branchIds();
        $branchIds = StaffBranches::resolve($changes->branchIds, $actingUser, $current);

        $before = ['branches' => $current, 'name' => $employee->name->all()];

        DB::connection('tenant')->transaction(function () use ($employee, $name, $branchIds, $current): void {
            $this->lock->acquire(array_values(array_unique([...$current, ...$branchIds])));

            $employee->forceFill(['name' => $name])->save();
            $employee->branches()->sync($branchIds);

            $user = $employee->user;

            if ($user instanceof User) {
                $user->forceFill(['name' => $name->get()])->save();
            }
        });

        $this->audit->record(new AuditEvent(
            action: 'employees.employee.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Employee::class,
            targetId: $employee->uuid,
            targetLabel: (string) $employee->name,
            before: $before,
            after: ['branches' => $branchIds, 'name' => $name->all()],
        ));

        return $employee->refresh();
    }
}
