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
use Illuminate\Validation\ValidationException;

/**
 * Sets which roles a staff account holds.
 *
 * Guards, in order:
 *
 *  - `staff.access.manage`.
 *  - The owner account's roles are changed by nobody else, and the owner may
 *    never drop the Owner role — a center that strips Owner from its only
 *    owner locks itself out of its own subscription.
 *  - Nobody changes their own roles otherwise (a manager who removes their
 *    own access manager role has locked the staff page).
 *  - The target must be inside the actor's branch scope, and must hold nothing
 *    the actor does not ({@see StaffAccessRules}).
 *  - Nobody may grant a role carrying a permission they do not hold.
 */
final class AssignRolesToUser
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  list<int>  $roleIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, array $roleIds, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::StaffAccessManage)) {
            throw new AuthorizationException(__('permissions.errors.access_denied'));
        }

        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $self = (int) $actingUser->getKey() === (int) $user->getKey();

        // The owner account keeps its role. A center that removes Owner from
        // its only owner locks itself out of its own subscription, and no
        // support tool exists yet to put it back.
        if ($user->is_owner && ! $self) {
            throw new AuthorizationException(__('permissions.errors.owner_roles'));
        }

        if ($user->is_owner) {
            $ownerRole = Role::query()->where('key', SystemRole::Owner->value)->value('id');

            if ($ownerRole !== null && ! in_array((int) $ownerRole, $roleIds, true)) {
                throw new AuthorizationException(__('permissions.errors.owner_keeps_role'));
            }
        } elseif ($self) {
            throw new AuthorizationException(__('permissions.errors.self_roles'));
        } else {
            StaffAccessRules::assertReaches($user, $actingUser);
            StaffAccessRules::assertNotOutranked($user, $actingUser);
        }

        $held = $actingUser->permissions();

        $roles = Role::query()->whereIn('id', $roleIds)->get();

        if ($roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages(['roles' => __('permissions.errors.role_unknown')]);
        }

        foreach ($roles as $role) {
            if (array_diff($role->permissionCodes(), $held) !== []) {
                throw new AuthorizationException(__('permissions.errors.role_escalation'));
            }
        }

        $before = $user->roles()->pluck('key')->all();

        DB::connection('tenant')->transaction(function () use ($user, $roleIds): void {
            $user->roles()->sync($roleIds);
        });

        $user->forgetPermissionCache();

        $after = $user->roles()->pluck('key')->all();

        $this->audit->record(new AuditEvent(
            action: 'authorization.user.roles_changed',
            category: AuditCategory::Security,
            actor: new Actor(ActorType::Staff, AuditSource::Web, (string) $actingUser->getKey(), $actingUser->name),
            severity: AuditSeverity::Critical,
            targetType: User::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            before: ['roles' => $before],
            after: ['roles' => $after],
            meta: ['owner_role' => SystemRole::Owner->value],
        ));
    }
}
