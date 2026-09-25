<?php

declare(strict_types=1);

namespace App\Modules\PlatformOperations\Application;

use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\PlatformOperations\Domain\Models\TenantOperationalProjection;
use Illuminate\Support\Facades\DB;

final class RefreshOperationalProjections
{
    public function __invoke(): int
    {
        $count = 0;
        TenantModel::query()->select(['id', 'provisioning_status', 'migration_status', 'schema_version'])->eachById(function (TenantModel $tenant) use (&$count): void {
            $open = DB::connection('control')->table('support_tickets')->where('tenant_id', $tenant->id)->whereNotIn('status', ['resolved', 'closed'])->count();
            $alerts = DB::connection('control')->table('platform_alerts')->where('tenant_id', $tenant->id)->where('is_active', true)->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->count();
            $last = DB::connection('control')->table('tenant_operations')->where('tenant_id', $tenant->id)->max('finished_at');
            $health = ($tenant->provisioning_status === 'completed' && $tenant->migration_status === 'succeeded' && $alerts === 0) ? 'healthy' : (($tenant->provisioning_status === 'failed' || $tenant->migration_status === 'failed') ? 'critical' : 'attention');
            TenantOperationalProjection::query()->updateOrCreate(['tenant_id' => $tenant->id], ['health' => $health, 'provisioning_status' => $tenant->provisioning_status, 'migration_status' => $tenant->migration_status, 'schema_version' => $tenant->schema_version, 'open_support_tickets' => $open, 'active_alerts' => $alerts, 'last_operation_at' => $last, 'projected_at' => now()]);
            $count++;
        }, 200);

        return $count;
    }
}
