<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Tenancy\Enums\MigrationStatus;
use App\Kernel\Tenancy\Enums\OperationType;
use App\Kernel\Tenancy\Exceptions\TenantDatabaseMissing;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\Infrastructure\TenantOperation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Applies the tenant migration set to tenant databases.
 *
 * The package can iterate tenants and switch connections; what it does not
 * provide, and what this class exists for, is the part that matters
 * operationally (docs/03-DATABASE-MIGRATIONS.md §4):
 *
 *   - per-tenant status recorded in the control plane, so drift is observable
 *   - FAILURE ISOLATION: tenant B failing must not stop or mark A and C failed
 *   - a lock, so the same tenant is never migrated twice concurrently
 *   - a retry path that targets only what failed
 */
final class TenantMigrator
{
    private const LOCK_SECONDS = 1800;

    public function __construct(
        private readonly StanclTenantContext $context,
        private readonly Audit $audit,
    ) {}

    /**
     * Migrates one tenant. Never throws for a migration failure: the failure
     * is recorded and reported, because a batch must continue.
     */
    public function migrate(TenantModel $tenant, Actor $actor): TenantMigrationResult
    {
        $lock = Cache::lock('metastyle:tenant:migrate:'.$tenant->getTenantKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return TenantMigrationResult::skipped($tenant->getTenantKey(), 'already migrating');
        }

        $operation = TenantOperation::start($tenant->getTenantKey(), OperationType::Migrate);

        $tenant->forceFill(['migration_status' => MigrationStatus::Running->value])->save();

        $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
            action: 'tenancy.tenant.migration_attempted',
            category: AuditCategory::Tenancy,
            actor: $actor,
            targetType: TenantModel::class,
            targetId: $tenant->getTenantKey(),
            targetLabel: $tenant->name,
            meta: ['attempt' => $operation->attempt],
        ));

        try {
            $this->runMigrations($tenant);

            $tenant->forceFill([
                'migration_status' => MigrationStatus::Succeeded->value,
                'schema_version' => self::targetSchemaVersion(),
                'last_migration_at' => Carbon::now(),
                'last_migration_error' => null,
            ])->save();

            $operation->succeed();

            $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
                action: 'tenancy.tenant.migration_succeeded',
                category: AuditCategory::Tenancy,
                actor: $actor,
                targetType: TenantModel::class,
                targetId: $tenant->getTenantKey(),
                targetLabel: $tenant->name,
                after: ['schema_version' => self::targetSchemaVersion()],
            ));

            return TenantMigrationResult::succeeded($tenant->getTenantKey());
        } catch (Throwable $e) {
            $error = TenantOperation::sanitize($e);

            $tenant->forceFill([
                'migration_status' => MigrationStatus::Failed->value,
                'last_migration_error' => $error,
            ])->save();

            $operation->fail($e);

            $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
                action: 'tenancy.tenant.migration_failed',
                category: AuditCategory::Tenancy,
                actor: $actor,
                severity: AuditSeverity::Critical,
                targetType: TenantModel::class,
                targetId: $tenant->getTenantKey(),
                targetLabel: $tenant->name,
                meta: ['error' => $error, 'attempt' => $operation->attempt],
            ));

            return TenantMigrationResult::failed($tenant->getTenantKey(), $error);
        } finally {
            $lock->release();
        }
    }

    /**
     * Migrates many tenants, one independent attempt each.
     *
     * @param  iterable<TenantModel>  $tenants
     * @return list<TenantMigrationResult>
     */
    public function migrateMany(iterable $tenants, Actor $actor): array
    {
        $results = [];

        foreach ($tenants as $tenant) {
            // Each tenant is its own try/catch inside migrate(). One failure
            // must never abort the loop — that is the whole point.
            $results[] = $this->migrate($tenant, $actor);
        }

        return $results;
    }

    /**
     * Runs the tenant migration set inside the tenant's own context, so
     * `migrate` targets that tenant's database and writes to that tenant's own
     * `migrations` table.
     */
    private function runMigrations(TenantModel $tenant): void
    {
        // Cheap defence in depth before any DDL: confirm the name is one Meta
        // Style generated, since database identifiers cannot be bound.
        $database = TenantDatabaseName::assertValid((string) $tenant->tenancy_db_name);

        // Checked on the central connection, BEFORE entering tenant context.
        //
        // Laravel's `migrate --force` creates a missing MySQL/MariaDB database
        // rather than failing. That is convenient for a single-database app and
        // wrong here: a stale or mistyped name would produce an empty orphan
        // database and report success. Provisioning owns database creation.
        if (! $tenant->database()->manager()->databaseExists($database)) {
            throw TenantDatabaseMissing::for($database, $tenant->getTenantKey());
        }

        $this->context->runForModel($tenant, function (): void {

            $exitCode = Artisan::call('migrate', [
                '--database' => TenantConnectionGuard::CONNECTION,
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    "Tenant migration exited with code {$exitCode}: ".Artisan::output()
                );
            }
        });
    }

    /**
     * The schema version every tenant should reach: the newest tenant
     * migration filename. Deterministic, derived from the code, and directly
     * comparable across tenants to detect drift.
     */
    public static function targetSchemaVersion(): string
    {
        $files = glob(database_path('migrations/tenant/*.php')) ?: [];

        if ($files === []) {
            return 'empty';
        }

        sort($files);

        return basename((string) end($files), '.php');
    }
}
