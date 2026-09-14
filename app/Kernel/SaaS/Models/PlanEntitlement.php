<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One capability granted by one plan.
 *
 * @property int $plan_id
 * @property string $entitlement
 */
final class PlanEntitlement extends Model
{
    protected $connection = 'control';

    protected $table = 'plan_entitlements';

    protected $guarded = [];

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
