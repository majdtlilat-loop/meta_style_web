<?php

declare(strict_types=1);

namespace App\Kernel\Reconciliation\Console;

use App\Kernel\Reconciliation\Contracts\Reconciler;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Replays every registered reconciler, per center — the recovery half of the
 * after-commit model.
 *
 * A reaction that runs after its fact committed (loyalty earned on a payment, a
 * package activated by one) can be lost to a failure or to a process that died
 * between the commit and the callback. This finds what is missing from the
 * canonical facts and writes it, exactly once: every reconciler is idempotent
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §1).
 *
 * Scheduled hourly over a short window (`routes/console.php`); run with a wider
 * `--days` after an outage. Each center, and each reconciler within it, is
 * attempted independently — one failure is reported and stepped over, and the
 * command exits non-zero (docs/02-TENANCY.md §7).
 */
final class ReconcileCommand extends Command
{
    public const TAG = 'metastyle.reconcilers';

    protected $signature = 'metastyle:reconcile
        {--tenant= : Only this tenant id}
        {--days=3 : How far back to look for facts to replay}';

    protected $description = 'Repair after-commit derived state (loyalty, memberships, packages) from canonical facts';

    public function handle(TenantContext $context): int
    {
        $days = (int) $this->option('days');

        if ($days < 1 || $days > 3650) {
            $this->components->error('--days must be between 1 and 3650.');

            return self::INVALID;
        }

        $since = CarbonImmutable::now()->utc()->subDays($days);
        $only = $this->option('tenant');
        $only = is_string($only) && $only !== '' ? $only : null;

        $query = TenantModel::query()->orderBy('sequence');

        if ($only !== null) {
            $query->whereKey($only);
        }

        $repaired = 0;
        $failed = 0;

        foreach ($query->get() as $tenant) {
            /** @var TenantModel $tenant */
            if ($tenant->tenancy_db_name === null) {
                continue;
            }

            foreach ($this->laravel->tagged(self::TAG) as $reconciler) {
                if (! $reconciler instanceof Reconciler) {
                    continue;
                }

                try {
                    $repaired += (int) $context->run($tenant->toValueObject(), fn (): int => $reconciler->reconcile($since));
                } catch (Throwable $e) {
                    $failed++;
                    $this->components->error("{$reconciler->name()} failed for center {$tenant->getKey()}: {$e->getMessage()}");
                }
            }
        }

        $this->components->info("{$repaired} item(s) repaired.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
