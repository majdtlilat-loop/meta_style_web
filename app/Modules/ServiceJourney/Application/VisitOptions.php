<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application;

use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\JourneyStageResource;
use Illuminate\Support\Facades\DB;

/**
 * The CHOICES a staff visit screen offers — never the decision.
 *
 * Reception picks a branch, services and a stylist; a host reassigns a stage or
 * swaps a room. Every list here is already narrowed to what the Action would
 * accept (active, at this branch, qualified), so the screen stops offering
 * choices that can only be refused. The Actions still validate everything
 * they are sent: a crafted request with an unqualified employee is refused by
 * `CreateWalkInVisit` / `ReassignStageEmployee`, not by this list.
 *
 * Plain arrays, so a Livewire component never holds a model it could write.
 */
final class VisitOptions
{
    /**
     * Active branches the viewer works in.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function branches(User $viewer): array
    {
        $query = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');

        $viewer->branchScope()->applyTo($query, 'id');

        return $query->get()
            ->map(static fn (Branch $branch): array => [
                'uuid' => $branch->uuid,
                'name' => (string) $branch->name->get(),
            ])
            ->values()
            ->all();
    }

    /**
     * An in-scope branch by uuid, or null.
     */
    public function branch(User $viewer, string $uuid): ?Branch
    {
        if ($uuid === '') {
            return null;
        }

        $branch = Branch::query()->active()->where('uuid', $uuid)->first();

        return $branch instanceof Branch && $viewer->canAccessBranch((int) $branch->getKey())
            ? $branch
            : null;
    }

    /**
     * Active services offered at the branch, in menu order.
     *
     * @return list<array{uuid: string, name: string, duration: int}>
     */
    public function services(Branch $branch): array
    {
        return Service::query()
            ->active()
            ->atBranch((int) $branch->getKey())
            ->get()
            ->map(static fn (Service $service): array => [
                'uuid' => $service->uuid,
                'name' => (string) $service->name->get(),
                'duration' => (int) $service->duration_minutes,
            ])
            ->values()
            ->all();
    }

    /**
     * Active team members at the branch qualified for EVERY given service.
     *
     * The same two pivots `CreateWalkInVisit` checks (`employee_branches`,
     * `employee_service`), in one query rather than one per service.
     *
     * @param  list<string>  $serviceUuids
     * @return list<array{uuid: string, name: string}>
     */
    public function employeesFor(Branch $branch, array $serviceUuids): array
    {
        $query = Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->whereExists(function ($sub) use ($branch): void {
                $sub->selectRaw('1')->from('employee_branches')
                    ->whereColumn('employee_branches.employee_id', 'employees.id')
                    ->where('employee_branches.branch_id', $branch->getKey());
            })
            ->orderBy('id');

        $serviceIds = $serviceUuids === []
            ? []
            : Service::query()->whereIn('uuid', $serviceUuids)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        foreach ($serviceIds as $serviceId) {
            $query->whereExists(function ($sub) use ($serviceId): void {
                $sub->selectRaw('1')->from('employee_service')
                    ->whereColumn('employee_service.employee_id', 'employees.id')
                    ->where('employee_service.service_id', $serviceId);
            });
        }

        return $query->get()
            ->map(static fn (Employee $employee): array => [
                'uuid' => $employee->uuid,
                'name' => (string) $employee->name->get(),
            ])
            ->values()
            ->all();
    }

    /**
     * Who a stage could be handed to: active, at the visit's branch, qualified
     * for its service — and not the person already doing it.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function reassignCandidates(JourneyStage $stage): array
    {
        $branch = Branch::query()->find($stage->journey->branchId());

        if (! $branch instanceof Branch) {
            return [];
        }

        $service = $stage->serviceId();
        $services = $service === null ? [] : Service::query()->whereKey($service)->pluck('uuid')->all();

        return array_values(array_filter(
            $this->employeesFor($branch, array_values(array_map('strval', $services))),
            static fn (array $employee): bool => $employee['uuid'] !== $stage->employee?->uuid,
        ));
    }

    /**
     * What a stage is holding now, and what each of those could be swapped for.
     *
     * Candidates are the SAME kind of resource at the same branch that a new
     * hold may take (`isBookable`). Capacity is not predicted here: the swap
     * Action takes the branch lock and runs the combined occupancy check, and
     * a refusal from it is the answer (ADR-050).
     *
     * @return list<array{uuid: string, name: string, candidates: list<array{uuid: string, name: string}>}>
     */
    public function swapOptions(JourneyStage $stage): array
    {
        /** @var list<JourneyStageResource> $open */
        $open = $stage->openResources()->with('resource')->get()->all();

        if ($open === []) {
            return [];
        }

        $branchId = $stage->journey->branchId();
        $options = [];

        foreach ($open as $usage) {
            $resource = $usage->resource;

            if (! $resource instanceof OperationalResource) {
                continue;
            }

            $candidates = OperationalResource::query()
                ->bookableAt($branchId)
                ->where('resource_type_id', $resource->resource_type_id)
                ->whereKeyNot($resource->getKey())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(static fn (OperationalResource $candidate): array => [
                    'uuid' => $candidate->uuid,
                    'name' => (string) $candidate->name->get(),
                ])
                ->values()
                ->all();

            $options[] = [
                'uuid' => $resource->uuid,
                'name' => (string) $resource->name->get(),
                'candidates' => $candidates,
            ];
        }

        return $options;
    }

    /**
     * Active departments, for the board filter.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function departments(): array
    {
        return Department::query()->active()->orderBy('sort_order')->orderBy('id')->get()
            ->map(static fn (Department $department): array => [
                'uuid' => $department->uuid,
                'name' => (string) $department->name->get(),
            ])
            ->values()
            ->all();
    }

    /**
     * Active team members working at the viewer's branches (or one of them),
     * for the board filter.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function teamInScope(User $viewer, ?Branch $branch = null): array
    {
        $scope = $viewer->branchScope();

        $branchIds = DB::connection('tenant')->table('branches')->where('is_active', true)->whereNull('archived_at');
        $scope->applyTo($branchIds, 'id');
        $ids = $branch instanceof Branch ? [(int) $branch->getKey()] : $branchIds->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        return Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->whereExists(function ($sub) use ($ids): void {
                $sub->selectRaw('1')->from('employee_branches')
                    ->whereColumn('employee_branches.employee_id', 'employees.id')
                    ->whereIn('employee_branches.branch_id', $ids === [] ? [-1] : $ids);
            })
            ->orderBy('id')
            ->get()
            ->map(static fn (Employee $employee): array => [
                'uuid' => $employee->uuid,
                'name' => (string) $employee->name->get(),
            ])
            ->values()
            ->all();
    }
}
