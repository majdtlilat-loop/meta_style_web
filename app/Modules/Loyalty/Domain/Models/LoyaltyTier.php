<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A loyalty tier: reached when a customer's lifetime points meet the threshold.
 * Derived, never assigned (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §9).
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property TranslatedText|null $benefit_note
 * @property int $threshold_points
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class LoyaltyTier extends Model
{
    use UsesTenantConnection;

    protected $table = 'loyalty_tiers';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'benefit_note' => Translatable::class,
            'threshold_points' => 'integer',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $tier): void {
            $tier->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
