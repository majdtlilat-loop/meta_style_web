<?php

declare(strict_types=1);

namespace App\Kernel\Http\Console;

use App\Kernel\Http\Idempotency;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deletes expired idempotency keys from every tenant, or one.
 *
 * The table prunes the common case on its own — an expired row is reclaimed by
 * the next request that reuses its key. But a center with steady traffic and no
 * retries never revisits those keys, so without this the table grows by one row
 * per booking forever.
 *
 * Scheduled hourly (`routes/console.php`). Not merely housekeeping: a completed
 * row holds the RESPONSE BODY it replays, so how long expired rows sit around
 * is a retention question, and hourly bounds a 24-hour key at about 25 hours
 * instead of the ~48 a daily sweep would allow.
 *
 * Deliberately NOT done inside a request: sweeping is a scheduled job's work,
 * and doing it in whichever request happened to be unlucky makes one customer's
 * booking slower than everyone else's for no reason they could understand.
 *
 * ## One center cannot stall the rest
 *
 * The scheduler runs this across every center, so a single unreachable database
 * must not abort the pass and leave every center after it unswept
 * (docs/02-TENANCY.md §7). Each center is therefore attempted independently and
 * a failure is reported and stepped over — but the command still exits non-zero,
 * because a sweep that silently skipped half the platform is not a success.
 */
final class SweepIdempotencyKeysCommand extends Command
{
    protected $signature = 'metastyle:idempotency:sweep
        {--tenant= : Sweep only this tenant id}';

    protected $description = 'Delete expired idempotency keys from tenant databases';

    public function handle(TenantContext $context, Idempotency $idempotency): int
    {
        $only = $this->option('tenant');
        $only = is_string($only) && $only !== '' ? $only : null;

        $query = TenantModel::query()->orderBy('sequence');

        if ($only !== null) {
            $query->whereKey($only);
        }

        $swept = 0;
        $centers = 0;
        $failed = 0;

        foreach ($query->get() as $tenant) {
            /** @var TenantModel $tenant */
            if ($tenant->tenancy_db_name === null) {
                // Provisioning never finished; there is no database to sweep.
                continue;
            }

            $centers++;

            try {
                // Each center in its own context: the table lives in that
                // center's own database, and no cross-tenant query could do
                // this in one pass (docs/02-TENANCY.md §1).
                $swept += (int) $context->run($tenant->toValueObject(), fn (): int => $idempotency->sweep());
            } catch (Throwable $e) {
                $failed++;

                // The id, not the database name: the name is derived from the
                // internal sequence and the sequence leaks how many centers
                // exist (ADR-024).
                $this->components->error("Sweep failed for center {$tenant->getKey()}: {$e->getMessage()}");
            }
        }

        $this->components->info("{$swept} expired key(s) deleted across {$centers} center(s).");

        if ($failed > 0) {
            $this->components->warn("{$failed} center(s) could not be swept.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
