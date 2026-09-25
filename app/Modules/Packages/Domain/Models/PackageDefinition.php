<?php

declare(strict_types=1);

namespace App\Modules\Packages\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A service package the center sells. What customers bought is a snapshot of
 * it (`CustomerPackage`), so editing this changes nothing already sold.
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property int $price_minor
 * @property int $validity_days
 * @property int $sort_order
 * @property Carbon|null $archived_at
 * @property-read Collection<int, PackageDefinitionItem> $items
 */
final class PackageDefinition extends Model
{
    use UsesTenantConnection;

    protected $table = 'package_definitions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'price_minor' => 'integer',
            'validity_days' => 'integer',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $definition): void {
            $definition->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<PackageDefinitionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PackageDefinitionItem::class)->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
