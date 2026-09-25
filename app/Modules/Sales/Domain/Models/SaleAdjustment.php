<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A discount or surcharge on a draft, with the reason somebody gave — or, for a
 * benefit, the label its owning module gave and the opaque source it can be
 * reclaimed by.
 *
 * @property int $id
 * @property string $uuid
 * @property int $sale_id
 * @property int|null $sale_item_id the one line a benefit belongs to, if any
 * @property AdjustmentType $type
 * @property int|null $basis_points
 * @property int $amount_minor
 * @property string $reason
 * @property int $position
 * @property string|null $source_type set only for a benefit; opaque to Sales
 * @property string|null $source_reference
 * @property string|null $created_by_id
 * @property string|null $created_by_label
 */
final class SaleAdjustment extends Model
{
    use UsesTenantConnection;

    protected $table = 'sale_adjustments';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'sale_item_id' => 'integer',
            'basis_points' => 'integer',
            'amount_minor' => 'integer',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $adjustment): void {
            $adjustment->uuid ??= (string) Str::uuid();
        });
    }
}
