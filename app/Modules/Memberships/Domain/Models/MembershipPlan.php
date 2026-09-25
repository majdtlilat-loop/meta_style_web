<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A membership plan the center sells. Customers' memberships are snapshots of
 * it, so editing it changes what is sold next and nothing else.
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property int $price_minor
 * @property int $duration_days
 * @property int $sort_order
 * @property Carbon|null $archived_at
 * @property-read Collection<int, MembershipPlanBenefit> $benefits
 */
final class MembershipPlan extends Model
{
    use UsesTenantConnection;

    protected $table = 'membership_plans';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'price_minor' => 'integer',
            'duration_days' => 'integer',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $plan): void {
            $plan->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<MembershipPlanBenefit, $this>
     */
    public function benefits(): HasMany
    {
        return $this->hasMany(MembershipPlanBenefit::class)->orderBy('id');
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
