<?php

declare(strict_types=1);

namespace App\Kernel\Authorization\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Renames one of the center's own roles.
 *
 * System roles keep the names Meta Style gives them in every language
 * (`SystemRole::name()`): support, documentation and every other center call
 * them the same thing, and a renamed "Owner" is how somebody is fooled about
 * who holds what.
 */
final class RenameRole
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, string|null>  $name  locale => value
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Role $role, array $name, User $actingUser): Role
    {
        if (! $actingUser->hasPermission(Permission::RoleUpdate)) {
            throw new AuthorizationException(__('permissions.errors.update_denied'));
        }

        if ($role->isProtected()) {
            throw new AuthorizationException(__('permissions.errors.system_role'));
        }

        $before = $role->name->all();
        $text = RoleNames::validated($name, $role);

        // Languages the center has switched off keep their stored text: the
        // form only shows enabled ones, and disabling never deletes
        // translations (docs/07-LOCALIZATION.md).
        $merged = $before;

        foreach ($name as $locale => $value) {
            unset($merged[(string) $locale]);
        }

        $role->forceFill(['name' => [...$merged, ...$text->all()]])->save();

        $this->audit->record(new AuditEvent(
            action: 'authorization.role.renamed',
            category: AuditCategory::Security,
            actor: Actor::staff($actingUser),
            targetType: Role::class,
            targetId: $role->uuid,
            targetLabel: (string) $role->name,
            before: ['name' => $before],
            after: ['name' => $role->name->all()],
        ));

        return $role;
    }
}
