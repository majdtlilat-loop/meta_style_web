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
use App\Kernel\Tenancy\Enums\ProvisioningStatus;
use App\Kernel\Tenancy\Enums\TenantStatus;
use App\Kernel\Tenancy\Exceptions\TenantProvisioningFailed;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\Infrastructure\TenantOperation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates a tenant, end to end.
 *
 * The pipeline (docs/02-TENANCY.md §8.2):
 *
 *     control record → database name → CREATE DATABASE
 *       → tenant migrations → system seed → mark active
 *
 * Two properties matter more than the steps:
 *
 *  - It never reports success it did not achieve. A tenant that fails at any
 *    step is left `failed`, not `active`, so nothing downstream can mistake a
 *    half-built tenant for a working one.
 *  - It is resumable. Each step tolerates having already run, so retrying a
 *    failed provision continues rather than starting over or duplicating.
 *
 * Self-service signup is NOT here — that is Phase 3. Phase 2 provisions via
 * console commands, application services and tests.
 */
final class TenantProvisioningService
{
    private const LOCK_SECONDS = 600;

    public function __construct(
        private readonly StanclTenantContext $context,
        private readonly TenantMigrator $migrator,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  list<string>  $domains
     * @param  string|null  $slug  the center's slug, which labels its database
     *                             (ADR-106). Without one the database takes the
     *                             neutral label — never anything from $name.
     *
     * @throws TenantProvisioningFailed
     */
    public function provision(string $name, array $domains = [], ?Actor $actor = null, ?string $slug = null): Tenant
    {
        $actor ??= Actor::system('provisioning');

        $tenant = $this->createRecord($name, $slug, $actor);

        return $this->runPipeline($tenant, $domains, $actor);
    }

    /**
     * Resumes a provisioning attempt that previously failed.
     *
     * @param  list<string>  $domains
     *
     * @throws TenantProvisioningFailed
     */
    public function retry(TenantModel $tenant, array $domains = [], ?Actor $actor = null): Tenant
    {
        return $this->runPipeline($tenant, $domains, $actor ?? Actor::system('provisioning.retry'));
    }

    /**
     * Creates the control-plane record and allocates the database name.
     *
     * The name is generated ONCE, here, and stored on the row: its label from
     * the slug, reduced to a closed alphabet, and its unique suffix from the
     * database-assigned `sequence`. The display name never reaches it — a
     * center called "Salon'; DROP DATABASE --" with no slug is `tenant_center_…`
     * (ADR-024, ADR-106). Nothing re-derives it later: a rename or a new
     * address leaves the database where it is.
     */
    private function createRecord(string $name, ?string $slug, Actor $actor): TenantModel
    {
        /** @var TenantModel $tenant */
        $tenant = DB::connection('control')->transaction(function () use ($name, $slug): TenantModel {
            /** @var TenantModel $tenant */
            $tenant = TenantModel::create([
                'name' => $name,
                'status' => TenantStatus::Provisioning->value,
                'provisioning_status' => ProvisioningStatus::Pending->value,
                'migration_status' => MigrationStatus::Pending->value,
            ]);

            // `sequence` is AUTO_INCREMENT, so its value only exists after the
            // insert.
            $tenant->refresh();

            $tenant->forceFill([
                'tenancy_db_name' => TenantDatabaseName::generate($tenant->sequence, $slug),
            ])->save();

            return $tenant;
        });

        $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
            action: 'tenancy.tenant.created',
            category: AuditCategory::Tenancy,
            actor: $actor,
            targetType: TenantModel::class,
            targetId: $tenant->getTenantKey(),
            targetLabel: $tenant->name,
            after: [
                'name' => $tenant->name,
                'database' => $tenant->tenancy_db_name,
                'sequence' => $tenant->sequence,
            ],
        ));

        return $tenant;
    }

    /**
     * @param  list<string>  $domains
     *
     * @throws TenantProvisioningFailed
     */
    private function runPipeline(TenantModel $tenant, array $domains, Actor $actor): Tenant
    {
        $lock = Cache::lock('metastyle:tenant:provision:'.$tenant->getTenantKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new TenantProvisioningFailed(
                "Tenant [{$tenant->getTenantKey()}] is already being provisioned."
            );
        }

        $operation = TenantOperation::start($tenant->getTenantKey(), OperationType::Provision);

        $tenant->forceFill(['provisioning_status' => ProvisioningStatus::Running->value])->save();

        $step = 'start';

        try {
            $step = 'create_database';
            $this->createDatabase($tenant, $actor);

            $step = 'migrate';
            $result = $this->migrator->migrate($tenant, $actor);

            if ($result->isFailed()) {
                throw new \RuntimeException($result->error ?? 'tenant migration failed');
            }

            $step = 'seed';
            $this->seedSystemData($tenant);

            $step = 'domains';
            $this->attachDomains($tenant, $domains);

            $step = 'activate';
            $tenant->forceFill([
                'status' => TenantStatus::Active->value,
                'provisioning_status' => ProvisioningStatus::Completed->value,
                'provisioned_at' => Carbon::now(),
            ])->save();

            $operation->succeed();

            $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
                action: 'tenancy.tenant.provisioned',
                category: AuditCategory::Tenancy,
                actor: $actor,
                targetType: TenantModel::class,
                targetId: $tenant->getTenantKey(),
                targetLabel: $tenant->name,
                after: ['status' => TenantStatus::Active->value],
            ));

            return $tenant->refresh()->toValueObject();
        } catch (Throwable $e) {
            $this->markFailed($tenant, $operation, $actor, $step, $e);

            throw TenantProvisioningFailed::at($step, $tenant->getTenantKey(), $e);
        } finally {
            $lock->release();
        }
    }

    private function createDatabase(TenantModel $tenant, Actor $actor): void
    {
        $database = TenantDatabaseName::assertValid((string) $tenant->tenancy_db_name);

        $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
            action: 'tenancy.database.provision_attempted',
            category: AuditCategory::Tenancy,
            actor: $actor,
            targetType: TenantModel::class,
            targetId: $tenant->getTenantKey(),
            targetLabel: $tenant->name,
            meta: ['database' => $database],
        ));

        $manager = $tenant->database()->manager();

        // Idempotent: a retry after a failure later in the pipeline must not
        // trip over the database it already created.
        if (! $manager->databaseExists($database)) {
            $manager->createDatabase($tenant);
        }

        $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
            action: 'tenancy.database.provision_succeeded',
            category: AuditCategory::Tenancy,
            actor: $actor,
            targetType: TenantModel::class,
            targetId: $tenant->getTenantKey(),
            targetLabel: $tenant->name,
            meta: ['database' => $database],
        ));
    }

    /**
     * Seeds the minimum tenant-local system data.
     *
     * Idempotent by construction (updateOrCreate on a stable key), so a retry
     * or a later re-seed after adding a new default is safe
     * (docs/03-DATABASE-MIGRATIONS.md §8).
     */
    private function seedSystemData(TenantModel $tenant): void
    {
        $this->context->runForModel($tenant, function () use ($tenant): void {
            $settings = [
                'tenant_key' => $tenant->getTenantKey(),
                'tenant_name' => $tenant->name,
                'schema_version' => TenantMigrator::targetSchemaVersion(),
                'provisioned_at' => Carbon::now()->toIso8601String(),
            ];

            foreach ($settings as $key => $value) {
                DB::connection(TenantConnectionGuard::CONNECTION)
                    ->table('settings')
                    ->updateOrInsert(
                        ['key' => $key],
                        [
                            'value' => json_encode($value, JSON_THROW_ON_ERROR),
                            'updated_at' => Carbon::now(),
                            'created_at' => Carbon::now(),
                        ],
                    );
            }
        });
    }

    /**
     * @param  list<string>  $domains
     */
    private function attachDomains(TenantModel $tenant, array $domains): void
    {
        foreach ($domains as $index => $domain) {
            $tenant->domains()->updateOrCreate(
                ['domain' => mb_strtolower($domain)],
                ['is_primary' => $index === 0],
            );
        }
    }

    private function markFailed(
        TenantModel $tenant,
        TenantOperation $operation,
        Actor $actor,
        string $step,
        Throwable $e,
    ): void {
        $error = TenantOperation::sanitize($e);

        // Never Active. A half-provisioned tenant that looks ready is worse
        // than one that is plainly broken.
        $tenant->forceFill([
            'status' => TenantStatus::Failed->value,
            'provisioning_status' => ProvisioningStatus::Failed->value,
        ])->save();

        $operation->fail($e);

        $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
            action: 'tenancy.database.provision_failed',
            category: AuditCategory::Tenancy,
            actor: $actor,
            severity: AuditSeverity::Critical,
            targetType: TenantModel::class,
            targetId: $tenant->getTenantKey(),
            targetLabel: $tenant->name,
            meta: ['step' => $step, 'error' => $error],
        ));
    }
}
