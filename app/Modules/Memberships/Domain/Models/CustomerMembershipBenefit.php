<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Memberships\Domain\Enums\DiscountKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One benefit of a customer's membership, copied from the plan at activation
 * with the service's name. `uses_limit` is what the plan allowed per term, as it
 * was; what is used is proven from `membership_benefit_usages`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_membership_id
 * @property int|null $service_id
 * @property TranslatedText|null $service_name
 * @property DiscountKind $discount_type
 * @property int|null $basis_points
 * @property int|null $amount_minor
 * @property int|null $uses_limit
 */
final class CustomerMembershipBenefit extends Model
{
    use UsesTenantConnection;

    protected $table = 'customer_membership_benefits';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_membership_id' => 'integer',
            'service_id' => 'integer',
            'service_name' => Translatable::class,
            'discount_type' => DiscountKind::class,
            'basis_points' => 'integer',
            'amount_minor' => 'integer',
            'uses_limit' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $benefit): void {
            $benefit->uuid ??= (string) Str::uuid();
        });
    }

    /** Does this benefit apply to a line for that service? Null covers every service. */
    public function covers(int $serviceId): bool
    {
        return $this->service_id === null || $this->service_id === $serviceId;
    }
}
