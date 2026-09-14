<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Actions\UpdateRolePermissions;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Role and permission management.
 *
 * The permission catalog is served from code, so the picker cannot drift from
 * what the server actually enforces.
 */
#[Layout('components.layouts.app')]
final class Roles extends Component
{
    public ?int $editingRoleId = null;

    /** @var list<string> */
    public array $selected = [];

    public function edit(int $roleId): void
    {
        $role = Role::query()->findOrFail($roleId);

        $this->editingRoleId = $roleId;
        $this->selected = $role->permissionCodes();
    }

    public function save(UpdateRolePermissions $updatePermissions): void
    {
        if ($this->editingRoleId === null) {
            return;
        }

        $role = Role::query()->findOrFail($this->editingRoleId);

        try {
            $updatePermissions($role, $this->selected, $this->actor());
        } catch (AuthorizationException $e) {
            $this->addError('selected', $e->getMessage());

            return;
        }

        $this->editingRoleId = null;
        $this->selected = [];
    }

    public function render(): mixed
    {
        $user = $this->actor();

        return view('livewire.center.roles', [
            'canView' => $user->hasPermission(Permission::RoleView),
            'canManage' => $user->hasPermission(Permission::RolePermissionsManage),
            'roles' => $user->hasPermission(Permission::RoleView)
                ? Role::query()->with('permissions')->orderBy('id')->get()
                : collect(),
            'catalog' => Permission::cases(),
        ]);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
