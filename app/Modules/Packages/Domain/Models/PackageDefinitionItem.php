<?php

declare(strict_types=1);

namespace App\Modules\Packages\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service a package covers, and how many times. A null variation covers
 * any variation of the service.
 *
 * @property int $id
 * @property int $package_definition_id
 * @property int $service_id
 * @property int|null $service_variation_id
 * @property int $quantity
 * @property-read Service|null $service
 * @property-read ServiceVariation|null $variation
 */
final class PackageDefinitionItem extends Model
{
    use UsesTenantConnection;

    protected $table = 'package_definition_items';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'package_definition_id' => 'integer',
            'service_id' => 'integer',
            'service_variation_id' => 'integer',
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<ServiceVariation, $this>
     */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(ServiceVariation::class, 'service_variation_id');
    }
}
