<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Models;

use App\Kernel\Database\Concerns\AppendOnlyHistory;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What Loyalty saw at one instant: whether the center owned `loyalty`, and
 * which rule version was effective. Written once, never changed
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
 *
 * @property int $id
 * @property Carbon $observed_at
 * @property bool $owns_loyalty
 * @property int|null $loyalty_rule_version_id
 * @property PointsSource|null $source_type
 * @property string|null $source_uuid
 * @property Carbon $created_at
 * @property-read LoyaltyRuleVersion|null $version
 */
final class LoyaltyEarningObservation extends Model
{
    use AppendOnlyHistory;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'loyalty_earning_observations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'owns_loyalty' => 'boolean',
            'loyalty_rule_version_id' => 'integer',
            'source_type' => PointsSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LoyaltyRuleVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(LoyaltyRuleVersion::class, 'loyalty_rule_version_id');
    }
}
