<?php

declare(strict_types=1);

namespace App\Kernel\Authorization\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes one of the center's own roles.
 *
 * Refused for system roles (the deploy sync would recreate them, and nothing
 * may leave a center without an Owner role), and refused while anybody still
 * holds the role: deleting it would silently strip those people's access, and
 * "which of my staff just lost the till" is not a question a delete button
 * should create. Reassign them first; the check runs under a row lock so a
 * concurrent assignment cannot slip in between.
 *
 * A role is configuration, not history — audit entries keep its key and name —
 * so it is genuinely deleted rather than archived.
 */
final class DeleteRole
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Role $role, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::RoleDelete)) {
            throw new AuthorizationException(__('permissions.errors.delete_denied'));
        }

        if ($role->isProtected()) {
            throw new AuthorizationException(__('permissions.errors.system_role'));
        }

        $snapshot = [
            'key' => $role->key,
            'name' => $role->name->all(),
            'permissions' => $role->permissionCodes(),
        ];

        DB::connection('tenant')->transaction(function () use ($role): void {
            Role::query()->whereKey($role->getKey())->lockForUpdate()->first();

            $holders = DB::connection('tenant')->table('user_roles')->where('role_id', $role->getKey())->count();

            if ($holders > 0) {
                throw ValidationException::withMessages([
                    'role' => trans_choice('permissions.errors.still_held', $holders, ['count' => $holders]),
                ]);
            }

            $role->permissions()->delete();
            $role->delete();
        });

        $this->audit->record(new AuditEvent(
            action: 'authorization.role.deleted',
            category: AuditCategory::Security,
            actor: Actor::staff($actingUser),
            severity: AuditSeverity::Critical,
            targetType: Role::class,
            targetId: $role->uuid,
            targetLabel: (string) $role->name,
            before: $snapshot,
        ));
    }
}
