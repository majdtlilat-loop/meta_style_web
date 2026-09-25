<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Memberships\Domain\Enums\DiscountKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One benefit of a plan: a discount on a service (or on every service, with a
 * null service), optionally limited per term.
 *
 * @property int $id
 * @property int $membership_plan_id
 * @property int|null $service_id
 * @property DiscountKind $discount_type
 * @property int|null $basis_points
 * @property int|null $amount_minor
 * @property int|null $uses_per_term
 * @property-read Service|null $service
 */
final class MembershipPlanBenefit extends Model
{
    use UsesTenantConnection;

    protected $table = 'membership_plan_benefits';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'membership_plan_id' => 'integer',
            'service_id' => 'integer',
            'discount_type' => DiscountKind::class,
            'basis_points' => 'integer',
            'amount_minor' => 'integer',
            'uses_per_term' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
