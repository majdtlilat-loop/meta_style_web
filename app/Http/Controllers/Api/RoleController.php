<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Actions\AssignRolesToUser;
use App\Kernel\Authorization\Actions\UpdateRolePermissions;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Roles and their permission grants.
 */
final class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasPermission(Permission::RoleView)) {
            throw new AuthorizationException('You may not view roles.');
        }

        $roles = Role::query()->with('permissions')->orderBy('id')->get()
            ->map(fn (Role $role): array => [
                'uuid' => $role->uuid,
                'key' => $role->key,
                'name' => (string) $role->name,
                'is_system' => $role->is_system,
                'permissions' => $role->permissions->pluck('permission')->all(),
            ])->all();

        return ApiResponse::data([
            'roles' => $roles,
            // The catalog, so a client can render a permission picker without
            // hardcoding codes that would drift from the server's.
            'catalog' => array_map(
                fn (Permission $permission): array => [
                    'code' => $permission->value,
                    'group' => $permission->group(),
                ],
                Permission::cases(),
            ),
        ]);
    }

    public function updatePermissions(
        Request $request,
        string $uuid,
        UpdateRolePermissions $updatePermissions,
    ): JsonResponse {
        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'max:96'],
        ]);

        /** @var User $actingUser */
        $actingUser = $request->user();

        $role = Role::query()->where('uuid', $uuid)->firstOrFail();

        /** @var list<string> $permissions */
        $permissions = array_values($validated['permissions']);

        $updatePermissions($role, $permissions, $actingUser);

        return ApiResponse::data([
            'uuid' => $role->uuid,
            'permissions' => $role->refresh()->permissionCodes(),
        ]);
    }

    public function assignToUser(Request $request, string $uuid, AssignRolesToUser $assignRoles): JsonResponse
    {
        $validated = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer'],
        ]);

        /** @var User $actingUser */
        $actingUser = $request->user();

        $target = User::query()->where('uuid', $uuid)->firstOrFail();

        /** @var list<int> $roleIds */
        $roleIds = array_map('intval', $validated['role_ids']);

        $assignRoles($target, $roleIds, $actingUser);

        return ApiResponse::data([
            'uuid' => $target->uuid,
            'roles' => $target->roles()->pluck('key')->all(),
        ]);
    }
}
