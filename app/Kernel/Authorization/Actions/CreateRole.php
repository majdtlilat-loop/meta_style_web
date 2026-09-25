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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates one of the center's own roles.
 *
 * A custom permission set IS a custom role — grants flow only through
 * `user_roles`, never per user (docs/06 §4) — so this is where "let the front
 * desk see bookings but not prices" is built.
 *
 * The key is generated, never typed: it is a stable machine identifier, and a
 * name in Arabic or Kurdish has no sensible slug. A new role starts with the
 * permissions given here (only codes the creator holds, and only when they may
 * manage role permissions at all) or with none.
 */
final class CreateRole
{
    public function __construct(
        private readonly UpdateRolePermissions $updatePermissions,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $name  locale => value
     * @param  list<string>  $permissions
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(array $name, array $permissions, User $actingUser): Role
    {
        if (! $actingUser->hasPermission(Permission::RoleCreate)) {
            throw new AuthorizationException(__('permissions.errors.create_denied'));
        }

        if ($permissions !== [] && ! $actingUser->hasPermission(Permission::RolePermissionsManage)) {
            throw new AuthorizationException(__('permissions.errors.manage_denied'));
        }

        $text = RoleNames::validated($name);

        /** @var Role $role */
        $role = DB::connection('tenant')->transaction(function () use ($text, $permissions, $actingUser): Role {
            /** @var Role $role */
            $role = Role::query()->create([
                'key' => $this->key(),
                'name' => $text,
                'is_system' => false,
            ]);

            if ($permissions !== []) {
                ($this->updatePermissions)($role, $permissions, $actingUser);
            }

            return $role;
        });

        $this->audit->record(new AuditEvent(
            action: 'authorization.role.created',
            category: AuditCategory::Security,
            actor: Actor::staff($actingUser),
            severity: AuditSeverity::Notice,
            targetType: Role::class,
            targetId: $role->uuid,
            targetLabel: (string) $role->name,
            after: ['key' => $role->key, 'permissions' => $role->permissionCodes()],
        ));

        return $role;
    }

    private function key(): string
    {
        do {
            $key = 'custom-'.Str::lower(Str::random(10));
        } while (Role::query()->where('key', $key)->exists());

        return $key;
    }
}
