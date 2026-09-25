<?php

declare(strict_types=1);

namespace App\Modules\Packages\Domain\Models;

use App\Kernel\Database\Concerns\AppendOnlyHistory;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Packages\Domain\Enums\PackageMovement;
use App\Modules\Packages\Domain\Enums\PackageSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One package movement. Written once by `PackageLedger`; never updated, never
 * deleted (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §14).
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_package_id
 * @property int $customer_package_item_id
 * @property PackageMovement $kind
 * @property int $quantity
 * @property PackageSource $source_type
 * @property string $source_uuid
 * @property string|null $sale_uuid
 * @property int|null $journey_stage_id
 * @property string|null $reason
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
final class PackageTransaction extends Model
{
    use AppendOnlyHistory;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'package_transactions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_package_id' => 'integer',
            'customer_package_item_id' => 'integer',
            'kind' => PackageMovement::class,
            'quantity' => 'integer',
            'source_type' => PackageSource::class,
            'journey_stage_id' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $transaction): void {
            $transaction->uuid ??= (string) Str::uuid();
        });
    }
}
