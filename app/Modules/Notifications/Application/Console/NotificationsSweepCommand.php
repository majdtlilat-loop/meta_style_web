<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application\Console;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\Notifications\Application\ExpirySweep;
use App\Modules\Notifications\Application\NotificationCleanup;
use App\Modules\Notifications\Application\ReminderSweep;
use Illuminate\Console\Command;
use Throwable;

/**
 * The scheduled half of notifications: reminders, expiry warnings and cleanup.
 *
 * Three passes, per center, each bounded and each idempotent. Running this
 * twice in a row writes nothing the first run already wrote — the unique key on
 * `notifications(type, source_type, source_uuid)` sees to that — so a schedule
 * that overlaps itself, a manual run and a catch-up after an outage are all
 * safe (docs/23-NOTIFICATIONS.md §§12, 19).
 *
 * ## One center's failure stops nothing
 *
 * Per-tenant fault isolation, the same rule every scheduled entry follows
 * (docs/02-TENANCY.md §7): each center is attempted independently, a failure is
 * reported and stepped over, and the command exits non-zero so the run is
 * visible without being silent about the centers that worked.
 *
 * Nothing here is retried, and nothing loops: the next scheduled run is the
 * retry, and it will find exactly what this one left undone.
 */
final class NotificationsSweepCommand extends Command
{
    protected $signature = 'metastyle:notifications:sweep
        {--tenant= : Only this tenant id}';

    protected $description = 'Write appointment reminders and benefit expiry notices, and remove notifications past their retention';

    public function handle(
        TenantContext $context,
        ReminderSweep $reminders,
        ExpirySweep $expiry,
        NotificationCleanup $cleanup,
    ): int {
        $only = $this->option('tenant');
        $only = is_string($only) && $only !== '' ? $only : null;

        $query = TenantModel::query()->orderBy('sequence');

        if ($only !== null) {
            $query->whereKey($only);
        }

        $written = 0;
        $removed = 0;
        $failed = 0;

        foreach ($query->get() as $tenant) {
            /** @var TenantModel $tenant */
            if ($tenant->tenancy_db_name === null) {
                continue;
            }

            try {
                $context->run($tenant->toValueObject(), function () use (&$written, &$removed, $reminders, $expiry, $cleanup): void {
                    $written += $reminders->sweep();
                    $written += $expiry->sweep();
                    $removed += $cleanup->sweep();
                });
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("notifications sweep failed for center {$tenant->getKey()}: {$e->getMessage()}");
            }
        }

        $this->components->info("{$written} notification(s) written, {$removed} removed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
