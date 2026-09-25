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
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Changes what a role may do.
 *
 * Audited as a security change with before/after, because "who could do this
 * last month" is the question an incident actually asks.
 *
 * Three rules:
 *
 *  - Nobody may ADD a permission they do not hold. Only the added codes are
 *    checked: an editor who lacks one of the codes a role already carries can
 *    still narrow or extend it with what they do hold, and the code they lack
 *    stays exactly as it was. Checking the whole submitted set made such a role
 *    impossible to save at all.
 *  - The Owner role is read-only. It means "everything" by definition, the
 *    deploy sync re-adds whatever it lacks, and removing role management from
 *    it is how a center locks its owner out of its own roles.
 *  - One transaction: a half-applied permission set is a role nobody chose.
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
            throw new AuthorizationException(__('permissions.errors.manage_denied'));
        }

        if ($role->key === SystemRole::Owner->value) {
            throw new AuthorizationException(__('permissions.errors.owner_role_locked'));
        }

        $before = $role->permissionCodes();

        // Nobody may grant what they do not hold. Without this, anyone able to
        // edit roles could add every permission to a role they already have
        // and escalate in two steps.
        $held = $actingUser->permissions();

        foreach (array_diff($permissions, $before) as $code) {
            if (! in_array($code, $held, true)) {
                throw new AuthorizationException(__('permissions.errors.not_held', [
                    'permission' => $this->label($code),
                ]));
            }
        }

        // ...and a code the editor does not hold is not theirs to take away
        // either: it stays exactly as it was, whatever the form sent.
        $permissions = array_values(array_unique([...$permissions, ...array_diff($before, $held)]));

        DB::connection('tenant')->transaction(function () use ($role, $permissions): void {
            // Serialises two editors saving the same role at once.
            Role::query()->whereKey($role->getKey())->lockForUpdate()->first();

            $role->syncPermissions($permissions);
        });

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

    private function label(string $code): string
    {
        $key = 'permissions.codes.'.str_replace('.', '_', $code);
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $code;
    }
}
