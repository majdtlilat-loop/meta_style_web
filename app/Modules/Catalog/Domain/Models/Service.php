<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\Concerns\HasMedia;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Notes\Concerns\HasInternalNotes;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Something a center does for a customer, for a price, in a length of time.
 *
 * The central entity of the catalog. Everything else here — variations,
 * add-ons, branch availability, employee eligibility — hangs off it.
 *
 * PRICE IS AN INTEGER IN MINOR UNITS and the currency is a center-level
 * setting, not a column. One center has one price list in one currency; a
 * per-row currency would permit a menu that silently mixes IQD and USD
 * (docs/10-API-FOUNDATION.md §9).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $department_id
 * @property int|null $service_category_id
 * @property TranslatedText $name
 * @property TranslatedText|null $short_description
 * @property TranslatedText|null $description
 * @property int $duration_minutes
 * @property int $price_minor
 * @property bool $is_active
 * @property bool $is_public
 * @property bool $is_online_bookable
 * @property bool $available_at_all_branches
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class Service extends Model
{
    use HasInternalNotes;
    use HasMedia;
    use UsesTenantConnection;

    protected $table = 'services';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'short_description' => Translatable::class,
            'description' => Translatable::class,
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_online_bookable' => 'boolean',
            'available_at_all_branches' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $service): void {
            $service->uuid ??= (string) Str::uuid();
        });
    }

    public function mediaOwnerType(): MediaOwner
    {
        return MediaOwner::Service;
    }

    public function noteOwnerType(): NoteOwner
    {
        return NoteOwner::Service;
    }

    /**
     * The price, with its currency attached.
     *
     * A method rather than a cast: the currency lives in the center's settings,
     * not in a column on this row, so there is nothing on the model for a cast
     * to read. An explicit call also makes it obvious at every call site that
     * `price_minor` alone is not a price.
     */
    public function price(?Currency $currency = null): Money
    {
        return Money::fromMinor($this->price_minor, $currency ?? Currency::default());
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * @return HasMany<ServiceVariation, $this>
     */
    public function variations(): HasMany
    {
        return $this->hasMany(ServiceVariation::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsToMany<ServiceAddon, $this>
     */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(ServiceAddon::class, 'service_addon_service')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * Branches this service is explicitly restricted to.
     *
     * Empty when `available_at_all_branches` is true — "everywhere" needs no
     * rows, so adding a branch does not mean touching every service.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_service');
    }

    /**
     * Employees permitted to perform this service.
     *
     * ELIGIBILITY ONLY. Not a schedule, not availability, not a commission —
     * the Booking Engine will combine this with schedules, resources and
     * existing bookings when it exists (docs/13-ROADMAP.md Phase 4 §9).
     *
     * @return BelongsToMany<Employee, $this>
     */
    public function eligibleEmployees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_service');
    }

    public function isAvailableAtBranch(int $branchId): bool
    {
        if ($this->available_at_all_branches) {
            return true;
        }

        return $this->relationLoaded('branches')
            ? $this->branches->contains('id', $branchId)
            : $this->branches()->whereKey($branchId)->exists();
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->active()->where('is_public', true);
    }

    /**
     * Restricts to services offered at one branch.
     *
     * The `available_at_all_branches` shortcut has to be part of the QUERY, not
     * filtered in PHP afterwards — otherwise a center with 400 services loads
     * all of them to show one branch's menu.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeAtBranch(Builder $query, int $branchId): Builder
    {
        return $query->where(function (Builder $q) use ($branchId): void {
            $q->where('available_at_all_branches', true)
                ->orWhereExists(function ($sub) use ($branchId): void {
                    $sub->selectRaw('1')
                        ->from('branch_service')
                        ->whereColumn('branch_service.service_id', 'services.id')
                        ->where('branch_service.branch_id', $branchId);
                });
        });
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
