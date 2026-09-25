<?php

declare(strict_types=1);

namespace App\Modules\Packages\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One service a customer's package covers, copied at activation with its name.
 * `quantity` is what was allocated; what is left is proven from the history.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_package_id
 * @property int $service_id
 * @property int|null $service_variation_id
 * @property TranslatedText $name
 * @property TranslatedText|null $variation_name
 * @property int $quantity
 */
final class CustomerPackageItem extends Model
{
    use UsesTenantConnection;

    protected $table = 'customer_package_items';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_package_id' => 'integer',
            'service_id' => 'integer',
            'service_variation_id' => 'integer',
            'name' => Translatable::class,
            'variation_name' => Translatable::class,
            'quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    /** Does this item cover a line for that service and variation? */
    public function covers(int $serviceId, ?int $variationId): bool
    {
        return $this->service_id === $serviceId
            && ($this->service_variation_id === null || $this->service_variation_id === $variationId);
    }
}
