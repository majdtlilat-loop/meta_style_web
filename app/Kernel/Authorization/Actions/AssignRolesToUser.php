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

/**
 * Sets which roles a staff account holds.
 */
final class AssignRolesToUser
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  list<int>  $roleIds
     *
     * @throws AuthorizationException
     */
    public function __invoke(User $user, array $roleIds, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::StaffAccessManage)) {
            throw new AuthorizationException('You may not change staff access.');
        }

        // The owner account keeps its role. A center that removes Owner from
        // its only owner locks itself out of its own subscription, and no
        // support tool exists yet to put it back.
        if ($user->is_owner && $actingUser->getKey() !== $user->getKey()) {
            throw new AuthorizationException('The owner account\'s roles cannot be changed.');
        }

        $held = $actingUser->permissions();

        /** @var list<Role> $roles */
        $roles = Role::query()->whereIn('id', $roleIds)->get()->all();

        foreach ($roles as $role) {
            foreach ($role->permissionCodes() as $code) {
                if (! in_array($code, $held, true)) {
                    throw new AuthorizationException(
                        'You may not assign a role that grants permissions you do not have.'
                    );
                }
            }
        }

        $before = $user->roles()->pluck('key')->all();

        $user->roles()->sync($roleIds);
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
