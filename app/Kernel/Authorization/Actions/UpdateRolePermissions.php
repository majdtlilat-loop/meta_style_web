<?php

declare(strict_types=1);

namespace App\Kernel\Authorization\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Changes what a role may do.
 *
 * Audited as a security change with before/after, because "who could do this
 * last month" is the question an incident actually asks.
 */
final class UpdateRolePermissions
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  list<string>  $permissions
     *
     * @throws AuthorizationException
     */
    public function __invoke(Role $role, array $permissions, User $actingUser): Role
    {
        if (! $actingUser->hasPermission(Permission::RolePermissionsManage)) {
            throw new AuthorizationException('You may not change role permissions.');
        }

        // Nobody may grant what they do not hold. Without this, anyone able to
        // edit roles could add every permission to a role they already have
        // and escalate in two steps.
        $held = $actingUser->permissions();

        foreach ($permissions as $code) {
            if (! in_array($code, $held, true)) {
                throw new AuthorizationException(
                    "You may not grant [{$code}], because you do not hold it."
                );
            }
        }

        $before = $role->permissionCodes();

        $role->syncPermissions($permissions);

        $after = $role->refresh()->permissionCodes();

        $this->audit->record(new AuditEvent(
            action: 'authorization.role.permissions_changed',
            category: AuditCategory::Security,
            actor: new Actor(ActorType::Staff, AuditSource::Web, (string) $actingUser->getKey(), $actingUser->name),
            severity: AuditSeverity::Critical,
            targetType: Role::class,
            targetId: $role->uuid,
            targetLabel: (string) $role->name,
            before: ['permissions' => $before],
            after: ['permissions' => $after],
        ));

        return $role;
    }
}
