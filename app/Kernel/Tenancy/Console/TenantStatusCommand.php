<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Console;

use App\Kernel\Tenancy\Enums\MigrationStatus;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\TenantMigrator;
use Illuminate\Console\Command;

/**
 * Shows tenant lifecycle and schema state.
 *
 * `--drift` is the deploy gate: it exits non-zero while any tenant is behind
 * the target schema version or has a failed migration. The next release's
 * contract migrations cannot ship until this is clean
 * (docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md §3).
 */
final class TenantStatusCommand extends Command
{
    protected $signature = 'metastyle:tenant:status
        {--drift : Exit non-zero if any tenant is behind or failed}';

    protected $description = 'Show tenant provisioning and schema state';

    public function handle(): int
    {
        $target = TenantMigrator::targetSchemaVersion();

        /** @var list<TenantModel> $tenants */
        $tenants = TenantModel::query()->orderBy('sequence')->get()->all();

        if ($tenants === []) {
            $this->components->info('No tenants.');

            return self::SUCCESS;
        }

        $rows = [];
        $behind = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            $isBehind = $tenant->schema_version !== $target;
            $isFailed = $tenant->migration_status === MigrationStatus::Failed->value;

            if ($tenant->status === 'active' && $isBehind) {
                $behind++;
            }

            if ($isFailed) {
                $failed++;
            }

            $rows[] = [
                $tenant->sequence,
                $tenant->name,
                $tenant->status,
                $tenant->provisioning_status,
                $tenant->migration_status,
                $tenant->schema_version ?? '-',
                $tenant->last_migration_at?->toDateTimeString() ?? '-',
            ];
        }

        $this->table(
            ['#', 'Name', 'Status', 'Provisioning', 'Migration', 'Schema', 'Last migrated'],
            $rows,
        );

        $this->components->twoColumnDetail('Target schema', $target);
        $this->components->twoColumnDetail('Behind target', (string) $behind);
        $this->components->twoColumnDetail('Failed', (string) $failed);

        foreach ($tenants as $tenant) {
            if ($tenant->last_migration_error !== null) {
                $this->newLine();
                $this->components->error("{$tenant->name}: {$tenant->last_migration_error}");
            }
        }

        if ($this->option('drift') && ($behind > 0 || $failed > 0)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
