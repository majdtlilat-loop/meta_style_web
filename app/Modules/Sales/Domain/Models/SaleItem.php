<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Sales\Domain\Enums\PriceSource;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One charged line: a snapshot of what was sold, at the price it was sold for.
 *
 * `original_unit_price_minor` is what the source said and is never overwritten;
 * `unit_price_minor` is what is charged. They differ only through an explicit,
 * reasoned, audited override (docs/18-SALES.md §13).
 *
 * @property int $id
 * @property string $uuid
 * @property int $sale_id
 * @property int $position
 * @property SaleItemKind $kind
 * @property int|null $service_id
 * @property int|null $service_variation_id
 * @property int|null $product_id
 * @property int|null $journey_stage_id
 * @property int|null $employee_id
 * @property TranslatedText $name
 * @property TranslatedText|null $variation_name
 * @property int $quantity
 * @property int $original_unit_price_minor
 * @property PriceSource $price_source
 * @property int $unit_price_minor
 * @property string|null $price_override_reason
 * @property string|null $price_overridden_by_id
 * @property string|null $price_overridden_by_label
 * @property int $addons_unit_total_minor
 * @property int $line_subtotal_minor
 * @property int $discount_allocated_minor
 * @property int $line_total_minor
 * @property string $currency
 * @property string|null $note
 * @property string|null $offering_type for an `offering` line: which catalog sold it (opaque to Sales)
 * @property string|null $offering_reference and which item of that catalog
 */
final class SaleItem extends Model
{
    use UsesTenantConnection;

    protected $table = 'sale_items';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SaleItemKind::class,
            'price_source' => PriceSource::class,
            'name' => Translatable::class,
            'variation_name' => Translatable::class,
            'quantity' => 'integer',
            'position' => 'integer',
            'original_unit_price_minor' => 'integer',
            'unit_price_minor' => 'integer',
            'addons_unit_total_minor' => 'integer',
            'line_subtotal_minor' => 'integer',
            'discount_allocated_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return HasMany<SaleItemAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(SaleItemAddon::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isOverridden(): bool
    {
        return $this->price_override_reason !== null;
    }
}
