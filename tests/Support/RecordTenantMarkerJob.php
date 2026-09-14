<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\TenantConnectionGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Infrastructure test job: records which tenant it actually ran as.
 *
 * It writes into the TENANT database rather than a static, so the assertion is
 * about where the write landed, not merely what the job believed. A job that
 * thinks it is tenant A but writes to tenant B's database is the failure this
 * exists to catch.
 *
 * Deliberately not a business job — Phase 2 creates no business jobs.
 */
final class RecordTenantMarkerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $marker) {}

    public function handle(TenantContext $tenants): void
    {
        $tenant = $tenants->require();

        DB::connection(TenantConnectionGuard::CONNECTION)
            ->table('settings')
            ->updateOrInsert(
                ['key' => 'job_marker'],
                [
                    'value' => json_encode([
                        'marker' => $this->marker,
                        'ran_as' => $tenant->id,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ],
            );
    }
}
