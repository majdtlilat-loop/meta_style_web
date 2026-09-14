<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A class of operational resource: "Barber Chair", "Laser Machine",
 * "Treatment Room", "Hammam Room".
 *
 * Tenant-defined, because a barbershop, a laser clinic and a spa share none of
 * this vocabulary and a PHP enum would need a release every time a center
 * bought a different machine (docs/13-ROADMAP.md Phase 7 §2).
 *
 * Services are defined against the TYPE — "a laser session needs one treatment
 * room and one laser machine" — and booking picks a concrete
 * {@see OperationalResource} at the moment it books. That indirection is what lets one
 * service definition serve a center with one machine and a center with six.
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property TranslatedText|null $description
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class ResourceType extends Model
{
    use UsesTenantConnection;

    protected $table = 'resource_types';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'description' => Translatable::class,
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $type): void {
            $type->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<OperationalResource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(OperationalResource::class);
    }

    /**
     * @return HasMany<ServiceResourceRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(ServiceResourceRequirement::class);
    }

    /**
     * @param  Builder<ResourceType>  $query
     * @return Builder<ResourceType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isBookable(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }
}
