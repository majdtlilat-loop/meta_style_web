<?php

declare(strict_types=1);

namespace App\Kernel\Authorization;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Throwable;

/**
 * Brings a tenant's system roles in line with the catalog in code.
 *
 * This is the machinery that lets the Owner role be a set of explicit grants
 * rather than an `if ($user->isOwner()) return true` bypass (ADR-029). When a
 * later release adds `booking.appointment.cancel` to the catalog, every
 * existing Owner role would otherwise silently lack it — the account would look
 * fine and simply be unable to do the new thing.
 *
 * Running at provisioning alone is therefore not enough: it must also run on
 * deploy, which is what {@see synchroniseAll()} and the
 * `metastyle:roles:sync` command exist for
 * (docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md §3).
 *
 * Deliberately conservative with a center's own decisions:
 *
 *  - **Custom roles are never touched.** Only `is_system` roles are considered.
 *  - **User assignments are never touched.** Who holds which role is the
 *    center's business.
 *  - **Non-owner system roles keep the set they were seeded with.** Nobody
 *    silently gains access to a new feature because it shipped.
 *  - **Owner gains new catalog permissions**, because Owner means "everything"
 *    by definition, and an owner who cannot use a feature they are paying for
 *    is a support incident.
 */
final class SystemRoleSynchroniser
{
    public function __construct(
        private readonly StanclTenantContext $context,
        private readonly Audit $audit,
    ) {}

    /**
     * Creates any missing system roles with their seeded permissions.
     *
     * Runs inside tenant context.
     *
     * @return int roles created
     */
    public function seed(): int
    {
        $created = 0;

        foreach (SystemRole::cases() as $systemRole) {
            if (Role::query()->where('key', $systemRole->value)->exists()) {
                continue;
            }

            /** @var Role $role */
            $role = Role::query()->create([
                'key' => $systemRole->value,
                'name' => TranslatedText::fromArray($systemRole->name()),
                'is_system' => true,
            ]);

            $role->syncPermissions($systemRole->permissions());
            $created++;
        }

        return $created;
    }

    /**
     * Adds catalog permissions the Owner role is missing.
     *
     * @return int permissions added
     */
    public function syncOwner(): int
    {
        $owner = Role::query()->where('key', SystemRole::Owner->value)->first();

        if (! $owner instanceof Role) {
            return 0;
        }

        $before = $owner->permissionCodes();

        $owner->syncPermissions(Permission::cases());

        $after = $owner->refresh()->permissionCodes();

        return count(array_diff($after, $before));
    }

    public function seedAndSync(): void
    {
        $this->seed();
        $this->syncOwner();
    }

    /**
     * Synchronises one tenant, inside its own context.
     *
     * Never throws: a failure is returned as a result so a run across every
     * tenant can continue. Context is restored by `runForModel`'s `finally`
     * even when the callback dies, so one broken tenant cannot leave the next
     * one bound to the wrong database (docs/02-TENANCY.md §4).
     */
    public function synchronise(TenantModel $tenant): SystemRoleSyncResult
    {
        $key = $tenant->getTenantKey();

        if (! $tenant->toValueObject()->isProvisioned()) {
            return SystemRoleSyncResult::skipped($key, 'not fully provisioned');
        }

        try {
            /** @var array{roles: int, permissions: int} $counts */
            $counts = $this->context->runForModel($tenant, fn (): array => [
                'roles' => $this->seed(),
                'permissions' => $this->syncOwner(),
            ]);
        } catch (Throwable $e) {
            $error = sprintf('%s: %s', $e::class, mb_substr($e->getMessage(), 0, 300));

            $this->audit->recordForTenant($key, new AuditEvent(
                action: 'authorization.system_roles.sync_failed',
                category: AuditCategory::Security,
                actor: Actor::system('role-sync'),
                severity: AuditSeverity::Critical,
                targetType: TenantModel::class,
                targetId: $key,
                targetLabel: $tenant->name,
                meta: ['error' => $error],
            ));

            return SystemRoleSyncResult::failed($key, $error);
        }

        $result = SystemRoleSyncResult::synced($key, $counts['roles'], $counts['permissions']);

        // Only audited when something actually changed. A deploy that syncs a
        // thousand unchanged tenants must not write a thousand audit rows
        // saying nothing happened.
        if ($result->changedAnything()) {
            $this->audit->recordForTenant($key, new AuditEvent(
                action: 'authorization.system_roles.synced',
                category: AuditCategory::Security,
                actor: Actor::system('role-sync'),
                targetType: TenantModel::class,
                targetId: $key,
                targetLabel: $tenant->name,
                after: [
                    'roles_created' => $result->rolesCreated,
                    'permissions_added' => $result->permissionsAdded,
                ],
            ));
        }

        return $result;
    }

    /**
     * Synchronises many tenants, one independent attempt each.
     *
     * @param  iterable<TenantModel>  $tenants
     * @return list<SystemRoleSyncResult>
     */
    public function synchroniseMany(iterable $tenants): array
    {
        $results = [];

        foreach ($tenants as $tenant) {
            $results[] = $this->synchronise($tenant);
        }

        return $results;
    }
}
