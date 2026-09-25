<?php

declare(strict_types=1);

namespace App\Livewire\Center\Reviews\Concerns;

use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Employees\Domain\Models\Employee;

/**
 * The review filters' choices, by uuid, and their translation to the internal
 * ids the Reviews reads take. Branches are only those the viewer may work in —
 * the reads narrow to the scope again whatever is chosen (docs/22 §50). No
 * numeric id ever reaches the page or the component's state.
 */
trait ReviewFilterOptions
{
    /**
     * @return list<array{uuid: string, name: string}>
     */
    protected function branchOptions(User $user): array
    {
        return array_values($user->branchScope()->applyTo(Branch::query())
            ->orderByDesc('is_main')->orderBy('sort_order')->orderBy('id')
            ->limit(100)->get()
            ->map(fn (Branch $branch): array => ['uuid' => $branch->uuid, 'name' => (string) $branch->name->get()])
            ->all());
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    protected function ratedServiceOptions(): array
    {
        return array_values(Service::query()->orderBy('sort_order')->orderBy('id')->limit(300)->get()
            ->map(fn (Service $service): array => ['uuid' => $service->uuid, 'name' => (string) $service->name->get()])
            ->all());
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    protected function ratedEmployeeOptions(): array
    {
        return array_values(Employee::query()->orderBy('id')->limit(300)->get()
            ->map(fn (Employee $employee): array => ['uuid' => $employee->uuid, 'name' => (string) $employee->name->get()])
            ->all());
    }

    /**
     * The internal id behind a chosen uuid — only if it is one of the options
     * this viewer was offered; anything else is "no filter", never a guess.
     *
     * @param  list<array{uuid: string, name: string}>  $options
     * @param  class-string<Branch|Service|Employee>  $model
     */
    protected function idFor(array $options, string $uuid, string $model): ?int
    {
        if ($uuid === '' || ! in_array($uuid, array_column($options, 'uuid'), true)) {
            return null;
        }

        $id = $model::query()->where('uuid', $uuid)->value('id');

        return $id === null ? null : (int) $id;
    }
}
