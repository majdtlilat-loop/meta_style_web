<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets which services one member of staff performs — the `employee_service`
 * pivot, written from the EMPLOYEE's side.
 *
 * The same eligibility the service editor writes from the other side
 * (`SaveService` → `eligibleEmployees()->sync`), read by the Booking Engine's
 * `EmployeeAssigner`: a person with no services is never auto-assigned. It
 * lives in Catalog because the pivot is Catalog's; Employees must not import
 * Catalog.
 *
 * ## Permission
 *
 * `staff.update` plus branch scope over every branch the person works at —
 * this is an edit to a person's job, made from their profile, by whoever
 * manages that person. It is NOT `service.update`: changing who performs a
 * service from the service's side stays with the catalog editors, and the
 * profile must not become a back door into editing services.
 *
 * ## Lock
 *
 * Eligibility changes who is bookable, so the change takes the branch lock of
 * every branch the person works at (ADR-047). Appointments already made keep
 * their employee.
 */
final class SetEmployeeServices
{
    public function __construct(
        private readonly BranchLock $lock,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  list<string>  $serviceUuids
     * @return list<int> the service ids now assigned
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Employee $employee, array $serviceUuids, User $actingUser): array
    {
        if (! $actingUser->hasPermission(Permission::StaffUpdate)) {
            throw new AuthorizationException(__('manager_staff.errors.update_denied'));
        }

        $branchIds = $employee->branchIds();
        $scope = $actingUser->branchScope();

        if (! $scope->isUnrestricted()) {
            if ($branchIds === []) {
                throw new AuthorizationException(__('manager_staff.errors.outside_scope'));
            }

            foreach ($branchIds as $branchId) {
                if (! $scope->allows($branchId)) {
                    throw new AuthorizationException(__('manager_staff.errors.outside_scope'));
                }
            }
        }

        $serviceUuids = array_values(array_unique($serviceUuids));

        /** @var list<int> $before */
        $before = DB::connection('tenant')->table('employee_service')
            ->where('employee_id', $employee->getKey())
            ->pluck('service_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $services = Service::query()->whereIn('uuid', $serviceUuids)->get(['id', 'uuid', 'archived_at']);

        if ($services->count() !== count($serviceUuids)) {
            throw ValidationException::withMessages(['services' => __('manager_staff.errors.service_unknown')]);
        }

        foreach ($services as $service) {
            // A retired service may stay where it already is (history), but
            // nobody is newly made eligible for it.
            if ($service->isArchived() && ! in_array((int) $service->id, $before, true)) {
                throw ValidationException::withMessages(['services' => __('manager_staff.errors.service_unknown')]);
            }
        }

        /** @var list<int> $serviceIds */
        $serviceIds = $services->map(static fn (Service $service): int => (int) $service->id)->values()->all();

        DB::connection('tenant')->transaction(function () use ($employee, $serviceIds, $branchIds): void {
            $this->lock->acquire($branchIds);

            $connection = DB::connection('tenant');

            $stale = $connection->table('employee_service')->where('employee_id', $employee->getKey());

            if ($serviceIds !== []) {
                $stale->whereNotIn('service_id', $serviceIds);
            }

            $stale->delete();

            $existing = $connection->table('employee_service')
                ->where('employee_id', $employee->getKey())
                ->pluck('service_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            foreach (array_diff($serviceIds, $existing) as $serviceId) {
                $connection->table('employee_service')->insert([
                    'employee_id' => $employee->getKey(),
                    'service_id' => $serviceId,
                ]);
            }
        });

        sort($before);
        $after = $serviceIds;
        sort($after);

        if ($before !== $after) {
            $this->audit->record(new AuditEvent(
                action: 'catalog.employee_services.updated',
                category: AuditCategory::Config,
                actor: Actor::staff($actingUser),
                targetType: Employee::class,
                targetId: $employee->uuid,
                targetLabel: (string) $employee->name,
                before: ['services' => $before],
                after: ['services' => $after],
            ));
        }

        return $after;
    }
}
