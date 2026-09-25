<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How much of a metered resource a PLAN includes.
 *
 * `allowance` NULL means unlimited. The absence of a row means the plan says
 * nothing, and the system default applies — a distinction the column alone
 * cannot carry, which is why "unlimited" is a row with NULL rather than no row
 * (docs/26-USAGE-QUOTAS.md §4).
 *
 * @property int $plan_id
 * @property string $resource
 * @property int|null $allowance
 */
final class PlanLimit extends Model
{
    protected $connection = 'control';

    protected $table = 'plan_limits';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['allowance' => 'integer'];
    }
}
