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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Something a center sells over the counter that is not a service.
 *
 * A bottle of shampoo, a comb, a gift voucher card. Sellable at every branch,
 * found by name or scanned by barcode, priced — and nothing else.
 *
 * DELIBERATELY NOT INVENTORY. There is no stock level here, no cost, no
 * supplier, no batch. A `stock_quantity` added "for now" is the first line of an
 * inventory system nobody designed, and the day Inventory is built it will be a
 * module of its own that reads this row (docs/18-SALES.md §8).
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property string|null $sku
 * @property string|null $barcode
 * @property int $price_minor
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class Product extends Model
{
    use UsesTenantConnection;

    protected $table = 'products';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'price_minor' => 'integer',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $product): void {
            $product->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * The price with its currency attached — the currency lives in the center's
     * configuration, as it does for services.
     */
    public function price(?Currency $currency = null): Money
    {
        return Money::fromMinor($this->price_minor, $currency ?? Currency::default());
    }

    public function isSellable(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
