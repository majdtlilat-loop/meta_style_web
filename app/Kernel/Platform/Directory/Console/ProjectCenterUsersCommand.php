<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Directory\Console;

use App\Kernel\Platform\Directory\CenterUserDirectory;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Console\Command;

/**
 * Refreshes the Super Admin Center Users directory, one center at a time.
 * A center that cannot be read is reported and stepped over; the command then
 * exits non-zero so a scheduler notices (docs/02-TENANCY.md §7).
 */
final class ProjectCenterUsersCommand extends Command
{
    protected $signature = 'metastyle:center-users:project
        {--tenant= : Only this tenant id}';

    protected $description = 'Refresh the control-plane directory of center user accounts';

    public function handle(CenterUserDirectory $directory): int
    {
        $only = $this->option('tenant');
        if (is_string($only) && $only !== '') {
            $tenant = TenantModel::query()->find($only);
            if (! $tenant instanceof TenantModel) {
                $this->error('No such tenant.');

                return self::FAILURE;
            }
            $count = $directory->refreshTenant($tenant);
            $this->line($count < 0 ? 'failed' : $count.' account(s) projected');

            return $count < 0 ? self::FAILURE : self::SUCCESS;
        }

        $result = $directory->refreshAll();
        $this->line(sprintf('%d center(s), %d account(s), %d failed', $result['centers'], $result['accounts'], $result['failed']));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
