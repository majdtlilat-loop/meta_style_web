<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who performed a service line rung up at the till.
 *
 * The same eligibility Booking applies to a named employee: ACTIVE, assigned to
 * the sale's branch, and allowed to perform that service. Recorded on the line
 * (`sale_items.employee_id`) as a fact about the sale — never a commission, and
 * never on a customer's invoice.
 *
 * A visit's line already carries who performed its stage, from the journey;
 * that is what happened and is not re-typed at the till (docs/16, "planned and
 * actual stay separate").
 *
 * Read-only: a Sales read of the catalog's eligibility and the employee roster,
 * the way Sales already reads the catalog to price a line.
 */
final class LineEmployees
{
    /**
     * The employees a cashier may name for a service at a branch.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function forService(int $branchId, ?int $serviceId): array
    {
        if ($serviceId === null) {
            return [];
        }

        $locale = app()->getLocale();

        return array_map(static fn (Employee $employee): array => [
            'uuid' => $employee->uuid,
            'name' => $employee->name->get($locale),
        ], $this->eligible($branchId, $serviceId)->limit(100)->get()->all());
    }

    /**
     * The id of the employee named for a service line, or a refusal.
     *
     * @throws SaleFailed
     */
    public function resolve(Sale $sale, ?int $serviceId, string $employeeUuid): int
    {
        if ($serviceId === null) {
            throw SaleFailed::policy('Only a service line records who performed it.');
        }

        /** @var Employee|null $employee */
        $employee = $this->eligible($sale->branch_id, $serviceId)
            ->where('employees.uuid', $employeeUuid)
            ->first();

        if (! $employee instanceof Employee) {
            throw SaleFailed::policy('That employee cannot perform this service at this branch.');
        }

        return (int) $employee->getKey();
    }

    /**
     * Names for the employees on a sale's lines — one query for the whole sale.
     *
     * @param  list<int|null>  $ids
     * @return array<int, array{uuid: string, name: string}>
     */
    public function named(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));

        if ($ids === []) {
            return [];
        }

        $locale = app()->getLocale();
        $named = [];

        foreach (Employee::query()->whereIn('id', $ids)->get() as $employee) {
            /** @var Employee $employee */
            $named[(int) $employee->getKey()] = ['uuid' => $employee->uuid, 'name' => $employee->name->get($locale)];
        }

        return $named;
    }

    /**
     * @return Builder<Employee>
     */
    private function eligible(int $branchId, int $serviceId): Builder
    {
        return Employee::query()
            ->where('employees.status', EmployeeStatus::Active->value)
            ->whereHas('branches', fn (Builder $branches) => $branches->where('branches.id', $branchId))
            ->whereExists(function ($eligibility) use ($serviceId): void {
                // Catalog's own eligibility pivot: who may perform the service.
                $eligibility->selectRaw('1')
                    ->from((new Service)->eligibleEmployees()->getTable())
                    ->whereColumn((new Service)->eligibleEmployees()->getQualifiedRelatedPivotKeyName(), 'employees.id')
                    ->where((new Service)->eligibleEmployees()->getQualifiedForeignPivotKeyName(), $serviceId);
            })
            ->orderBy('employees.id');
    }
}
