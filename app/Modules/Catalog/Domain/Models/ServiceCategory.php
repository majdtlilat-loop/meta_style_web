<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\Concerns\HasMedia;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Departments\Domain\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * How the electronic menu is organised for a customer.
 *
 * Customer-facing only. A category carries no operational responsibility — it
 * does not route a queue, does not assign staff, does not appear in a journey.
 * That is a {@see Department}, and the
 * two are separate tables precisely so this one can be reorganised whenever
 * marketing wants without touching how the business runs.
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property TranslatedText|null $description
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class ServiceCategory extends Model
{
    use HasMedia;
    use UsesTenantConnection;

    protected $table = 'service_categories';

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
            'is_public' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $category): void {
            $category->uuid ??= (string) Str::uuid();
        });
    }

    public function mediaOwnerType(): MediaOwner
    {
        return MediaOwner::ServiceCategory;
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @param  Builder<ServiceCategory>  $query
     * @return Builder<ServiceCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @param  Builder<ServiceCategory>  $query
     * @return Builder<ServiceCategory>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->active()->where('is_public', true);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
