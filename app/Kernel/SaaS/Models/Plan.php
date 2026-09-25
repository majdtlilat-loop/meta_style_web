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
 * @property TranslatedText|null $description
 * @property int|null $trial_days
 * @property string $uuid
 * @property int $price_minor
 * @property int|null $monthly_price_minor
 * @property int|null $yearly_price_minor
 * @property string $currency
 * @property string $billing_period
 * @property bool $is_public
 * @property bool $is_active
 * @property bool $is_featured
 * @property int $sort_order
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
            'monthly_price_minor' => 'integer',
            'yearly_price_minor' => 'integer',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
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

    public const CYCLES = ['monthly', 'yearly'];

    /**
     * The commercial price for one billing cycle, in minor units of the
     * plan's currency — null when the plan is not sold on that cycle.
     *
     * Plans that predate per-cycle pricing only have their primary price.
     */
    public function priceFor(string $cycle): ?int
    {
        $price = match ($cycle) {
            'monthly' => $this->monthly_price_minor,
            'yearly' => $this->yearly_price_minor,
            default => null,
        };

        if ($price === null && $this->billing_period === $cycle) {
            return $this->price_minor;
        }

        return $price;
    }

    /** @return list<string> */
    public function cycles(): array
    {
        return array_values(array_filter(self::CYCLES, fn (string $cycle): bool => $this->priceFor($cycle) !== null));
    }

    public function offers(string $cycle): bool
    {
        return $this->priceFor($cycle) !== null;
    }

    /**
     * How much a year costs on the yearly price compared with twelve
     * monthly payments, in whole percent — null unless it is a real saving.
     */
    public function yearlySavingPercent(): ?int
    {
        $monthly = $this->priceFor('monthly');
        $yearly = $this->priceFor('yearly');
        if ($monthly === null || $yearly === null || $monthly <= 0) {
            return null;
        }
        $full = $monthly * 12;
        if ($yearly >= $full) {
            return null;
        }
        $percent = intdiv(($full - $yearly) * 100, $full);

        return $percent > 0 ? $percent : null;
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
