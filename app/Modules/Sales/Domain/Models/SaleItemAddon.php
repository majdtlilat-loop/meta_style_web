<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * An add-on charged on a line, as it was priced when it was added.
 *
 * @property int $id
 * @property int $sale_item_id
 * @property int|null $service_addon_id
 * @property TranslatedText $name
 * @property int $unit_price_minor
 * @property string $currency
 * @property int $sort_order
 */
final class SaleItemAddon extends Model
{
    use UsesTenantConnection;

    protected $table = 'sale_item_addons';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'unit_price_minor' => 'integer',
        ];
    }
}
