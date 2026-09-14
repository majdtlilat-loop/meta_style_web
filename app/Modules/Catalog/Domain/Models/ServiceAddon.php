<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * An optional extra a customer can add: Hair Wash, Styling, Hot Towel.
 *
 * SHARED, not owned by one service. "Hair Wash" applies to a haircut, a colour
 * and a treatment; owning it per service would mean three rows to edit when its
 * price changes, and three chances to miss one.
 *
 * Not a package and not a bundle. Those are priced products with their own
 * redemption rules and belong to the phase that sells them
 * (docs/13-ROADMAP.md Phase 4 §7).
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property int $price_minor
 * @property int $duration_minutes
 * @property bool $is_active
 * @property int $sort_order
 */
final class ServiceAddon extends Model
{
    use UsesTenantConnection;

    protected $table = 'service_addons';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $addon): void {
            $addon->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * An add-on always has its own price — there is nothing to inherit, because
     * "haircut plus wash" is not "haircut plus a fraction of a haircut".
     */
    public function price(?Currency $currency = null): Money
    {
        return Money::fromMinor($this->price_minor, $currency ?? Currency::default());
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_addon_service')->withPivot('sort_order');
    }

    /**
     * @param  Builder<ServiceAddon>  $query
     * @return Builder<ServiceAddon>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }
}
