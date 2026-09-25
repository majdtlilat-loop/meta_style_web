<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Console;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Usage\UsageProjector;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refreshes the Super Admin usage read model, one center at a time.
 *
 * PER-TENANT FAULT ISOLATION, like every other entry that walks every center:
 * one center whose database is unreachable is reported and stepped over, and
 * the command exits non-zero so a scheduler notices — but the other ninety-nine
 * are still projected (docs/02-TENANCY.md §7).
 *
 * Correctness never depends on this running. The projection is a report; the
 * authoritative counters are in the tenant databases and are what every quota
 * decision reads (docs/26-USAGE-QUOTAS.md §9). A missed run means Super Admin's
 * numbers are an hour stale, not that a center was over- or under-charged.
 */
final class ProjectUsageCommand extends Command
{
    protected $signature = 'metastyle:usage:project
        {--tenant= : Only this tenant id}';

    protected $description = 'Refresh the control-plane usage projection from each center\'s counters';

    public function handle(TenantContext $context, UsageProjector $projector): int
    {
        $only = $this->option('tenant');
        $only = is_string($only) && $only !== '' ? $only : null;

        $query = TenantModel::query()->orderBy('sequence');

        if ($only !== null) {
            $query->whereKey($only);
        }

        $projected = 0;
        $failed = 0;

        foreach ($query->get() as $tenant) {
            /** @var TenantModel $tenant */
            if ($tenant->tenancy_db_name === null) {
                // Provisioning never finished. Nothing to read.
                continue;
            }

            try {
                $projected += (int) $context->run(
                    $tenant->toValueObject(),
                    fn (): int => $projector->project(),
                );
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("Usage projection failed for center {$tenant->getKey()}: {$e->getMessage()}");
            }
        }

        $this->components->info("{$projected} projection row(s) written.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
