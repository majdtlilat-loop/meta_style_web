<?php

declare(strict_types=1);

namespace App\Kernel\Authorization\Console;

use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRoleSynchroniser;
use App\Kernel\Authorization\SystemRoleSyncResult;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Console\Command;

/**
 * Brings every tenant's system roles in line with the permission catalog.
 *
 * **This belongs in the deploy sequence.** Owner is explicit grants, not a
 * bypass (ADR-029), so a release that adds a permission leaves every existing
 * Owner role missing it until this runs. The failure mode is quiet: the owner
 * simply cannot use the new feature, and nothing logs an error.
 *
 * Failure-isolated like the tenant migrator: one tenant failing neither stops
 * the run nor affects any other, and the command exits non-zero afterwards so
 * a deploy notices.
 */
final class SyncSystemRolesCommand extends Command
{
    protected $signature = 'metastyle:roles:sync
        {--tenant= : Synchronise a single tenant by id}
        {--all : Synchronise every provisioned tenant}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Synchronise system roles with the permission catalog across tenants';

    public function handle(SystemRoleSynchroniser $synchroniser, TenantContext $context): int
    {
        $tenants = $this->select();

        if ($tenants === []) {
            $this->components->info('No tenants matched.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Synchronising %d tenant(s) against %d catalog permission(s)',
            count($tenants),
            count(Permission::cases()),
        ));

        if ($this->option('dry-run')) {
            foreach ($tenants as $tenant) {
                $this->components->twoColumnDetail($tenant->name, $tenant->getTenantKey());
            }

            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $results = $synchroniser->synchroniseMany($tenants);

        $this->report($results);

        // The context must be clean when the command ends, whatever happened
        // to individual tenants. Anything queued or scheduled afterwards in the
        // same process would otherwise inherit the last tenant.
        if ($context->isBound()) {
            $context->forget();

            $this->components->warn('Tenant context was still bound after the run and has been cleared.');
        }

        $failed = array_filter($results, fn (SystemRoleSyncResult $r): bool => $r->isFailed());

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

        if (! $this->option('all')) {
            $this->components->error('Specify --tenant=<id> or --all.');

            return [];
        }

        /** @var list<TenantModel> $tenants */
        $tenants = TenantModel::query()->migratable()->orderBy('sequence')->get()->all();

        return $tenants;
    }

    /**
     * @param  list<SystemRoleSyncResult>  $results
     */
    private function report(array $results): void
    {
        $changed = 0;

        foreach ($results as $result) {
            if ($result->isFailed()) {
                $this->components->twoColumnDetail($result->tenantId, '<fg=red>FAILED</>');
                $this->line('    '.$result->error);

                continue;
            }

            if ($result->isSkipped()) {
                $this->components->twoColumnDetail($result->tenantId, '<fg=yellow>skipped</> '.$result->error);

                continue;
            }

            if ($result->changedAnything()) {
                $changed++;
                $this->components->twoColumnDetail($result->tenantId, sprintf(
                    '<fg=green>+%d role(s), +%d permission(s)</>',
                    $result->rolesCreated,
                    $result->permissionsAdded,
                ));
            }
        }

        $failed = count(array_filter($results, fn (SystemRoleSyncResult $r): bool => $r->isFailed()));

        $this->newLine();
        $this->components->twoColumnDetail('Total', sprintf(
            '%d checked, %d changed, %d failed',
            count($results),
            $changed,
            $failed,
        ));

        if ($failed > 0) {
            $this->line('  Re-run for the failures once their cause is fixed: '
                .'<comment>metastyle:roles:sync --tenant=<id></comment>');
        }
    }
}
