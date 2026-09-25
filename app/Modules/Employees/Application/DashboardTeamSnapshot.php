<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * The team as the Manager dashboard and the report filters see it: active
 * employees assigned to a branch the viewer may see.
 *
 * "Active" is the employee record's status — employed and bookable — never
 * attendance. There is no shift or clock-in record, and nothing here claims
 * one.
 */
final class DashboardTeamSnapshot
{
    /**
     * How many active employees work in the viewer's (optionally narrowed)
     * branches. Null without `staff.view`.
     */
    public function activeCount(User $viewer, ?string $branchUuid = null): ?int
    {
        if (! $viewer->hasPermission(Permission::StaffView)) {
            return null;
        }

        return $this->activeInScope($viewer, $branchUuid)->distinct()->count('employees.id');
    }

    /**
     * Active employees for a report filter, name order, uuid only — a report
     * resolves the uuid inside its own branch-scoped query, so choosing an
     * employee can never widen what the viewer sees.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function options(User $viewer): array
    {
        return $this->activeInScope($viewer, null)
            ->orderBy('employees.id')
            ->limit(500)
            ->get(['employees.id', 'employees.uuid', 'employees.name'])
            ->map(static fn (Employee $employee): array => ['uuid' => $employee->uuid, 'name' => $employee->name->get()])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @return Builder<Employee>
     */
    private function activeInScope(User $viewer, ?string $branchUuid): Builder
    {
        $query = Employee::query()->where('employees.status', EmployeeStatus::Active->value);
        $scope = $viewer->branchScope();
        $narrowed = is_string($branchUuid) && $branchUuid !== '';

        if ($scope->isUnrestricted() && ! $narrowed) {
            return $query;
        }

        // ONE branch condition: in scope AND (when asked) the chosen branch,
        // so an out-of-scope uuid narrows to nothing rather than widening.
        return $query->whereHas('branches', function (Builder $branches) use ($scope, $narrowed, $branchUuid): void {
            if (! $scope->isUnrestricted()) {
                $branches->whereIn('branches.id', $scope->branchIds ?? []);
            }

            if ($narrowed) {
                $branches->where('branches.uuid', $branchUuid);
            }
        });
    }
}
