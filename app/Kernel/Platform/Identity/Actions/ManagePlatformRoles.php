<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Platform\Authorization\PlatformPermission;
use App\Kernel\Platform\Authorization\PlatformRoleSynchroniser;
use App\Kernel\Platform\Identity\Models\PlatformRole;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform roles: named bundles of explicit platform permissions.
 *
 * There is no owner bypass anywhere — the system Super Admin role is simply
 * the role that holds every permission, kept complete by
 * {@see PlatformRoleSynchroniser} and not editable here. Nobody may put a
 * permission into a role that they do not hold themselves, and a role in use
 * is archived rather than deleted.
 */
final class ManagePlatformRoles
{
    /**
     * Starting points offered when creating a role. Each is only a
     * pre-selection; the person creating the role still chooses.
     */
    public const TEMPLATES = [
        'support_agent' => ['platform.dashboard.view', 'platform.center.view', 'platform.support.view', 'platform.support.manage'],
        'support_tickets_only' => ['platform.support.view', 'platform.support.manage'],
        'billing_agent' => ['platform.dashboard.view', 'platform.center.view', 'platform.subscription.manage', 'platform.billing.manage', 'platform.plan.manage'],
        'operations_agent' => ['platform.dashboard.view', 'platform.center.view', 'platform.operations.view', 'platform.operations.manage', 'platform.provider.view', 'platform.usage.manage'],
        'cms_manager' => ['platform.cms.manage'],
        'read_only' => ['platform.dashboard.view', 'platform.center.view', 'platform.operations.view', 'platform.support.view', 'platform.audit.view'],
    ];

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>  $description
     * @param  list<string>  $permissions
     */
    public function create(array $name, array $description, array $permissions, PlatformUser $actor): PlatformRole
    {
        $name = $this->names($name);
        $permissions = $this->permissions($permissions, [], $actor);
        $key = $this->uniqueKey($name['en']);

        /** @var PlatformRole $role */
        $role = DB::connection('control')->transaction(function () use ($key, $name, $description, $permissions): PlatformRole {
            /** @var PlatformRole $created */
            $created = PlatformRole::query()->create(['key' => $key, 'name' => $name, 'description' => $this->names($description, false), 'is_system' => false]);
            $this->writePermissions($created, $permissions);

            return $created;
        });

        $this->audit->record(new AuditEvent(
            action: 'platform.role.created',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            severity: AuditSeverity::Notice,
            targetType: PlatformRole::class,
            targetId: (string) $role->id,
            targetLabel: $role->key,
            after: ['name' => $name, 'permissions' => $permissions],
        ));

        return $role;
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>  $description
     * @param  list<string>  $permissions
     */
    public function update(PlatformRole $role, array $name, array $description, array $permissions, PlatformUser $actor): PlatformRole
    {
        $this->assertEditable($role);
        $name = $this->names($name);
        $current = $role->permissions();
        $permissions = $this->permissions($permissions, $current, $actor);

        DB::connection('control')->transaction(function () use ($role, $name, $description, $permissions): void {
            $role->forceFill(['name' => $name, 'description' => $this->names($description, false)])->save();
            $this->writePermissions($role, $permissions);
            $this->assertStillManageable();
        });

        $this->audit->record(new AuditEvent(
            action: 'platform.role.updated',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            severity: $current !== $permissions ? AuditSeverity::Warning : AuditSeverity::Notice,
            targetType: PlatformRole::class,
            targetId: (string) $role->id,
            targetLabel: $role->key,
            before: ['permissions' => $current],
            after: ['name' => $name, 'permissions' => $permissions],
        ));

        return $role->refresh();
    }

    /** Archiving takes the role away from everyone who holds it. */
    public function archive(PlatformRole $role, PlatformUser $actor, string $reason): void
    {
        $this->assertEditable($role);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new DomainException(__('sadmin_roles.errors.reason'));
        }
        $holders = $role->users()->pluck('platform_users.id')->all();

        DB::connection('control')->transaction(function () use ($role): void {
            $role->users()->detach();
            $role->forceFill(['archived_at' => now()])->save();
            $this->assertStillManageable();
        });

        $this->audit->record(new AuditEvent(
            action: 'platform.role.archived',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            severity: AuditSeverity::Warning,
            targetType: PlatformRole::class,
            targetId: (string) $role->id,
            targetLabel: $role->key,
            before: ['holders' => count($holders)],
            reason: $reason,
        ));
    }

    public function restore(PlatformRole $role, PlatformUser $actor): void
    {
        $role->forceFill(['archived_at' => null])->save();

        $this->audit->record(new AuditEvent(
            action: 'platform.role.restored',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            targetType: PlatformRole::class,
            targetId: (string) $role->id,
            targetLabel: $role->key,
        ));
    }

    /** Only an archived role nobody holds can be deleted outright. */
    public function delete(PlatformRole $role, PlatformUser $actor): void
    {
        $this->assertEditable($role);
        if ($role->archived_at === null || $role->users()->exists()) {
            throw new DomainException(__('sadmin_roles.errors.delete'));
        }
        $key = $role->key;
        $id = (string) $role->id;
        $role->delete();

        $this->audit->record(new AuditEvent(
            action: 'platform.role.deleted',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            severity: AuditSeverity::Warning,
            targetType: PlatformRole::class,
            targetId: $id,
            targetLabel: $key,
        ));
    }

    private function assertEditable(PlatformRole $role): void
    {
        if ($role->is_system || $role->key === PlatformRoleSynchroniser::SUPER_ADMIN) {
            throw new DomainException(__('sadmin_roles.errors.system'));
        }
    }

    /**
     * @param  array<array-key, mixed>  $requested
     * @param  list<string>  $current
     * @return list<string>
     */
    private function permissions(array $requested, array $current, PlatformUser $actor): array
    {
        $known = PlatformPermission::codes();
        $requested = array_values(array_unique(array_filter($requested, static fn (mixed $code): bool => is_string($code))));
        if (array_diff($requested, $known) !== []) {
            throw new DomainException(__('sadmin_roles.errors.unknown'));
        }
        if ($requested === []) {
            throw new DomainException(__('sadmin_roles.errors.empty'));
        }
        // Granting is what needs authority; a permission the role already had
        // is not a new grant.
        $added = array_diff($requested, $current);
        if (array_diff($added, $actor->permissions()) !== []) {
            throw new DomainException(__('sadmin_roles.errors.escalation'));
        }
        sort($requested);

        return $requested;
    }

    /** @param list<string> $permissions */
    private function writePermissions(PlatformRole $role, array $permissions): void
    {
        DB::connection('control')->table('platform_role_permissions')->where('role_id', $role->id)->delete();
        DB::connection('control')->table('platform_role_permissions')->insert(array_map(static fn (string $permission): array => [
            'role_id' => $role->id, 'permission' => $permission, 'created_at' => now(), 'updated_at' => now(),
        ], $permissions));
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function names(array $values, bool $required = true): array
    {
        $clean = [];
        foreach (['en', 'ar', 'ckb'] as $locale) {
            $clean[$locale] = mb_substr(trim((string) ($values[$locale] ?? '')), 0, 190);
        }
        if ($required && $clean['en'] === '') {
            throw new DomainException(__('sadmin_roles.errors.name'));
        }

        return $clean;
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 48, '') ?: 'role';
        $key = $base;
        $suffix = 2;
        while (PlatformRole::query()->where('key', $key)->exists()) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }

    private function assertStillManageable(): void
    {
        $managers = DB::connection('control')->table('platform_users')
            ->join('platform_user_roles', 'platform_user_roles.user_id', '=', 'platform_users.id')
            ->join('platform_role_permissions', 'platform_role_permissions.role_id', '=', 'platform_user_roles.role_id')
            ->where('platform_users.is_active', true)->whereNull('platform_users.archived_at')
            ->where('platform_role_permissions.permission', 'platform.user.manage')
            ->distinct()->count('platform_users.id');

        if ($managers < 1) {
            throw new DomainException(__('sadmin_users.errors.last_manager'));
        }
    }
}
