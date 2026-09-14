<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A manual, sale-level discount or surcharge, with the reason somebody gave.
 *
 * @property int $id
 * @property string $uuid
 * @property int $sale_id
 * @property AdjustmentType $type
 * @property int|null $basis_points
 * @property int $amount_minor
 * @property string $reason
 * @property int $position
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
