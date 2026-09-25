<?php

declare(strict_types=1);

namespace App\Livewire\Center\Staff;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Application\EmployeeQuery;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The option lists the staff screens offer, already narrowed to what the
 * viewer may actually choose.
 *
 * Presentation only — the Actions refuse anything outside these lists on the
 * server — but offering a branch or a role the person cannot use is a form
 * that fails on submit for reasons it never showed.
 */
final class StaffOptions
{
    /**
     * Branches in the viewer's scope. Live ones only, unless the list is a
     * filter over history.
     *
     * @return list<array{id: int, uuid: string, name: string, archived: bool}>
     */
    public static function branches(User $viewer, bool $includeArchived = false): array
    {
        $query = Branch::query()->orderBy('sort_order')->orderBy('id');

        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }

        $viewer->branchScope()->applyTo($query, 'id');

        return $query->get()->map(static fn (Branch $branch): array => [
            'id' => (int) $branch->id,
            'uuid' => $branch->uuid,
            'name' => $branch->name->get(),
            'archived' => $branch->isArchived(),
        ])->values()->all();
    }

    /**
     * Roles the viewer may hand out: every permission they carry is one the
     * viewer holds. Empty without `staff.access.manage`.
     *
     * @return list<array{id: int, uuid: string, name: string, system: bool, count: int}>
     */
    public static function grantableRoles(User $viewer): array
    {
        if (! $viewer->hasPermission(Permission::StaffAccessManage)) {
            return [];
        }

        $held = $viewer->permissions();

        return Role::query()->with('permissions')->orderByDesc('is_system')->orderBy('id')->get()
            ->filter(static fn (Role $role): bool => array_diff($role->permissions->pluck('permission')->all(), $held) === [])
            // Owner is the center's own account, never a role handed to staff
            // from this form.
            ->reject(static fn (Role $role): bool => $role->key === SystemRole::Owner->value)
            ->map(static fn (Role $role): array => [
                'id' => (int) $role->id,
                'uuid' => $role->uuid,
                'name' => $role->name->get(),
                'system' => $role->is_system,
                'count' => $role->permissions->count(),
            ])->values()->all();
    }

    /**
     * Counts over the people the viewer can see.
     *
     * @return array{total: int, active: int, login: int, pending: int}
     */
    public static function stats(EmployeeQuery $query, User $viewer): array
    {
        return [
            'total' => $query->visibleTo($viewer)->count(),
            'active' => $query->visibleTo($viewer)->where('status', EmployeeStatus::Active->value)->count(),
            'login' => $query->visibleTo($viewer)->whereHas('user', fn (Builder $u) => $u->whereNotNull('password')->where('is_active', true))->count(),
            'pending' => $query->visibleTo($viewer)->whereHas('user', fn (Builder $u) => $u->whereNull('password')->where('is_active', true))->count(),
        ];
    }

    /**
     * Validation attribute names for the staff forms.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'name' => __('manager_staff.fields.name'),
            'name.*' => __('manager_staff.fields.name'),
            'email' => __('manager_staff.fields.email'),
            'phone' => __('phone_field.label'),
            'phoneCountry' => __('phone_field.country'),
            'branchIds' => __('manager_staff.fields.branches'),
            'roleIds' => __('manager_staff.fields.roles'),
        ];
    }

    /**
     * An Action names its fields the way the API does (`branches`, `roles`);
     * the form's properties are called something else. Each message lands on
     * the field that caused it, never on a generic banner.
     */
    public static function remapErrors(Component $component, ValidationException $e, string $prefix = ''): void
    {
        $map = [
            'branches' => 'branchIds',
            'roles' => 'roleIds',
            'phone' => 'phone',
            'email' => 'email',
            'name' => 'name',
        ];

        foreach ($e->errors() as $field => $messages) {
            $target = $map[$field] ?? 'form';

            foreach ($messages as $message) {
                $component->addError($prefix.$target, $message);
            }
        }
    }
}
