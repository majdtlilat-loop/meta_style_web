<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Console;

use App\Kernel\Audit\Actor;
use App\Kernel\Tenancy\Enums\MigrationStatus;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\TenantMigrationResult;
use App\Kernel\Tenancy\TenantMigrator;
use Illuminate\Console\Command;

/**
 * Applies the tenant migration set (docs/03-DATABASE-MIGRATIONS.md §3).
 *
 *   metastyle:tenant:migrate --tenant=<id>   one tenant
 *   metastyle:tenant:migrate --all           every migratable tenant
 *   metastyle:tenant:migrate --retry-failed  only those that previously failed
 *   metastyle:tenant:migrate --all --limit=N a bounded slice
 *
 * Failure isolation is the contract: one tenant failing neither stops the run
 * nor marks any other tenant failed. The command exits non-zero if anything
 * failed, so CI and deploy scripts notice, but only after every tenant has had
 * its turn.
 */
final class MigrateTenantsCommand extends Command
{
    protected $signature = 'metastyle:tenant:migrate
        {--tenant= : Migrate a single tenant by id}
        {--all : Migrate every migratable tenant}
        {--retry-failed : Migrate only tenants whose last migration failed}
        {--limit=0 : Process at most this many tenants (0 = no limit)}';

    protected $description = 'Run tenant migrations with per-tenant failure isolation';

    public function handle(TenantMigrator $migrator): int
    {
        $tenants = $this->select();

        if ($tenants === []) {
            $this->components->info('No tenants matched.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Migrating %d tenant(s) to schema version [%s]',
            count($tenants),
            TenantMigrator::targetSchemaVersion(),
        ));

        $actor = Actor::console($this->getName() ?? 'metastyle:tenant:migrate');

        $results = $migrator->migrateMany($tenants, $actor);

        $this->report($results);

        $failed = array_filter($results, fn (TenantMigrationResult $r): bool => $r->isFailed());

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<TenantModel>
     */
    private function select(): array
    {
        $tenantId = $this->option('tenant');

        if (is_string($tenantId) && $tenantId !== '') {
            $tenant = TenantModel::query()->find($tenantId);

            return $tenant instanceof TenantModel ? [$tenant] : [];
        }

        $query = TenantModel::query()->migratable();

        if ($this->option('retry-failed')) {
            $query->where('migration_status', MigrationStatus::Failed->value);
        } elseif (! $this->option('all')) {
            $this->components->error('Specify --tenant=<id>, --all, or --retry-failed.');

            return [];
        }

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        /** @var list<TenantModel> $tenants */
        $tenants = $query->orderBy('sequence')->get()->all();

        return $tenants;
    }

    /**
     * @param  list<TenantMigrationResult>  $results
     */
    private function report(array $results): void
    {
        foreach ($results as $result) {
            match (true) {
                $result->isSucceeded() => $this->components->twoColumnDetail($result->tenantId, '<fg=green>migrated</>'),
                $result->isSkipped() => $this->components->twoColumnDetail($result->tenantId, '<fg=yellow>skipped</>'),
                default => $this->components->twoColumnDetail($result->tenantId, '<fg=red>FAILED</>'),
            };

            if ($result->isFailed()) {
                $this->line('    '.$result->error);
            }
        }

        $failed = count(array_filter($results, fn (TenantMigrationResult $r): bool => $r->isFailed()));

        $this->newLine();
        $this->components->twoColumnDetail(
            'Total',
            sprintf(
                '%d migrated, %d failed, %d skipped',
                count(array_filter($results, fn (TenantMigrationResult $r): bool => $r->isSucceeded())),
                $failed,
                count(array_filter($results, fn (TenantMigrationResult $r): bool => $r->isSkipped())),
            ),
        );

        if ($failed > 0) {
            $this->line('  Retry with: <comment>metastyle:tenant:migrate --retry-failed</comment>');
        }
    }
}
