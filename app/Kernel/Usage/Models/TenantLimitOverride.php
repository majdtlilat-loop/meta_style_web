<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One center's allowance, overriding whatever their plan says.
 *
 * `version` is the mechanism that makes synchronisation safe. Every write bumps
 * it, and a tenant's counter records the version its snapshot came from — so
 * "already applied" and "changed since" are distinguishable without comparing
 * allowances, which could never tell a stale copy from a decrease that is
 * correctly waiting for the next period (docs/26-USAGE-QUOTAS.md §8).
 *
 * @property string $tenant_id
 * @property string $resource
 * @property int|null $allowance
 * @property int $version
 * @property bool $enforce_immediately
 * @property string|null $reason
 * @property Carbon|null $updated_at
 */
final class TenantLimitOverride extends Model
{
    protected $connection = 'control';

    protected $table = 'tenant_limit_overrides';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowance' => 'integer',
            'version' => 'integer',
            'enforce_immediately' => 'boolean',
        ];
    }
}
