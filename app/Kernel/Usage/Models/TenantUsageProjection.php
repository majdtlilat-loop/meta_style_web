<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Models;

use App\Kernel\Usage\UsageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A COPY of one center's usage, in the control plane, for Super Admin reporting.
 *
 * DERIVED, LAGGING AND NON-AUTHORITATIVE, and every one of those words is load
 * bearing. It exists so the platform can see a hundred centers on one screen
 * without opening a hundred databases.
 *
 * NOTHING CONSUMES QUOTA FROM HERE. A quota decision reads and writes the
 * center's own `usage_counters` row and nothing else; deciding from this table
 * would mean deciding from a snapshot that is minutes old, which is precisely
 * the race the tenant-side counter was built to remove
 * (docs/26-USAGE-QUOTAS.md §9).
 *
 * @property string $tenant_id
 * @property string $resource
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $used
 * @property int|null $allowance
 * @property int|null $percent
 * @property UsageStatus $status
 * @property Carbon|null $last_activity_at
 * @property Carbon $projected_at
 */
final class TenantUsageProjection extends Model
{
    protected $connection = 'control';

    protected $table = 'tenant_usage_projections';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'used' => 'integer',
            'allowance' => 'integer',
            'percent' => 'integer',
            'status' => UsageStatus::class,
            'last_activity_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }
}
