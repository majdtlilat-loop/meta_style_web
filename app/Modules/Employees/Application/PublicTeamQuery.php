<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Contracts\PublicTeamReader;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;

/**
 * {@see PublicTeamReader} over the tenant's employees. Names only.
 */
final class PublicTeamQuery implements PublicTeamReader
{
    public function references(): array
    {
        $references = [];
        foreach (Employee::query()->get(['id', 'uuid', 'status']) as $employee) {
            $references[$employee->uuid] = $employee->status === EmployeeStatus::Active;
        }

        return $references;
    }

    public function options(string $locale): array
    {
        return Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->orderBy('id')
            ->get(['id', 'uuid', 'name'])
            ->map(fn (Employee $employee): array => ['uuid' => $employee->uuid, 'name' => $employee->name->get($locale)])
            ->values()->all();
    }

    public function members(array $uuids, string $locale): array
    {
        if ($uuids === []) {
            return [];
        }

        $members = [];
        foreach (Employee::query()->where('status', EmployeeStatus::Active->value)->whereIn('uuid', $uuids)->get(['id', 'uuid', 'name']) as $employee) {
            $members[$employee->uuid] = ['uuid' => $employee->uuid, 'name' => $employee->name->get($locale)];
        }

        return $members;
    }
}
