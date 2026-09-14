<?php

declare(strict_types=1);

namespace App\Modules\Departments\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\Concerns\HasMedia;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Notes\Concerns\HasInternalNotes;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An operational division of a center: Hair, Laser, Hammam, Nails, Skin Care.
 *
 * NOT a menu category, and the distinction is deliberate. A department is how
 * the BUSINESS is organised — it will drive the service journey, employee
 * assignment, queue routing, rooms and reporting. A
 * {@see ServiceCategory} is how the MENU is
 * organised for a customer, and a center may well group services from three
 * departments into one menu category because that is how customers shop.
 *
 * Collapsing them into one table with a flag would force every future query —
 * queue routing, journey stages, menu rendering — to remember the flag, and one
 * that forgot would route a customer to a menu heading.
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property TranslatedText|null $description
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class Department extends Model
{
    use HasInternalNotes;
    use HasMedia;
    use UsesTenantConnection;

    protected $table = 'departments';

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
        self::creating(function (self $department): void {
            $department->uuid ??= (string) Str::uuid();
        });
    }

    public function mediaOwnerType(): MediaOwner
    {
        return MediaOwner::Department;
    }

    public function noteOwnerType(): NoteOwner
    {
        return NoteOwner::Department;
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @param  Builder<Department>  $query
     * @return Builder<Department>
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
}
