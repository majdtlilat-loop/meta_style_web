<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A sellable bundle of entitlement grants.
 *
 * A plan is data. Adding "Business + Queue add-on" is rows, never code — if a
 * new plan needs a code change, the entitlement model has been violated
 * (docs/05-ENTITLEMENTS.md §9).
 *
 * @property int $id
 * @property string $code
 * @property TranslatedText $name
 * @property int|null $trial_days
 */
final class Plan extends Model
{
    protected $connection = 'control';

    protected $table = 'plans';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'description' => Translatable::class,
            'price_minor' => 'integer',
            'trial_days' => 'integer',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $plan): void {
            $plan->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<PlanEntitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    /**
     * @return list<string>
     */
    public function entitlementCodes(): array
    {
        /** @var list<string> $codes */
        $codes = $this->entitlements()->pluck('entitlement')->all();

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     */
    public function syncEntitlements(array $codes): void
    {
        $existing = $this->entitlementCodes();

        $this->entitlements()->whereIn('entitlement', array_diff($existing, $codes))->delete();

        foreach (array_diff($codes, $existing) as $code) {
            $this->entitlements()->create(['entitlement' => $code]);
        }
    }
}
