<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain\Models;

use App\Kernel\Database\Concerns\AppendOnlyHistory;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Memberships\Domain\Enums\UsageKind;
use App\Modules\Memberships\Domain\Enums\UsageSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One use of a membership benefit, or its return. Written once by
 * `MembershipUsageLedger`; never updated, never deleted
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §12).
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_membership_id
 * @property int $customer_membership_benefit_id
 * @property UsageKind $kind
 * @property int $quantity
 * @property UsageSource $source_type
 * @property string $source_uuid
 * @property string|null $sale_uuid
 * @property string|null $reason
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
final class MembershipBenefitUsage extends Model
{
    use AppendOnlyHistory;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'membership_benefit_usages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_membership_id' => 'integer',
            'customer_membership_benefit_id' => 'integer',
            'kind' => UsageKind::class,
            'quantity' => 'integer',
            'source_type' => UsageSource::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $usage): void {
            $usage->uuid ??= (string) Str::uuid();
        });
    }
}
