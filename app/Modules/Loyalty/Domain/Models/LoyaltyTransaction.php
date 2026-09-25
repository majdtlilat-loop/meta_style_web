<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Models;

use App\Kernel\Database\Concerns\AppendOnlyHistory;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One points movement. Written once by `LoyaltyLedger`; never updated, never
 * deleted (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §3).
 *
 * @property int $id
 * @property string $uuid
 * @property int $loyalty_account_id
 * @property PointsKind $kind
 * @property PointsDirection $direction
 * @property int $points
 * @property int $unrecovered_points
 * @property Carbon|null $expires_at snapshot on a credit; on a redeem row, the expiry its points get back
 * @property PointsSource $source_type
 * @property string $source_uuid
 * @property string|null $context_uuid
 * @property string|null $reason
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
final class LoyaltyTransaction extends Model
{
    use AppendOnlyHistory;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'loyalty_transactions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'loyalty_account_id' => 'integer',
            'kind' => PointsKind::class,
            'direction' => PointsDirection::class,
            'points' => 'integer',
            'unrecovered_points' => 'integer',
            'source_type' => PointsSource::class,
            'expires_at' => 'datetime',
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
