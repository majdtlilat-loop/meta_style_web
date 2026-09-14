<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Sales\Domain\Concerns\ImmutableDocument;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a published invoice. Immutable, like the invoice it belongs to.
 *
 * `addons` is a JSON snapshot — `[{name: {...}, unit_price_minor: int}]` —
 * because it is only ever rendered, never filtered.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $position
 * @property string $kind
 * @property TranslatedText $name
 * @property TranslatedText|null $variation_name
 * @property list<array{name: array<string, string>, unit_price_minor: int}> $addons
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $addons_unit_total_minor
 * @property int $line_subtotal_minor
 * @property int $discount_allocated_minor
 * @property int $line_total_minor
 * @property string $currency
 * @property int|null $sale_item_id
 * @property int|null $service_id
 * @property int|null $product_id
 */
final class InvoiceItem extends Model
{
    use ImmutableDocument;
    use UsesTenantConnection;

    protected $table = 'invoice_items';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'variation_name' => Translatable::class,
            'addons' => 'array',
            'position' => 'integer',
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'addons_unit_total_minor' => 'integer',
            'line_subtotal_minor' => 'integer',
            'discount_allocated_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }
}
