<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * "This threshold has already been announced."
 *
 * The whole reason a center is told about 85% once rather than on every message
 * after the 85th. The unique index is the mechanism; nothing asks whether an
 * alert was already raised (docs/26-USAGE-QUOTAS.md §13).
 *
 * @property string $resource
 * @property CarbonImmutable $period_start
 * @property int $threshold
 * @property CarbonImmutable $raised_at
 */
final class UsageAlert extends Model
{
    use UsesTenantConnection;

    protected $table = 'usage_alerts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_datetime',
            'threshold' => 'integer',
            'raised_at' => 'immutable_datetime',
        ];
    }
}
