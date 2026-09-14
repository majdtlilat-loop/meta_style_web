<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Departments\Domain\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One physical thing a service consumes while it runs: Chair #3, Laser Device
 * #2, the VIP room.
 *
 * ## The name
 *
 * `OperationalResource`, not `Resource`, for one flat reason: `resource` is a
 * native PHP type, and Pint's docblock normaliser rewrites the bare word to
 * lower case inside every `@param Builder<Resource>` it meets — which PHPStan
 * then reports as a class referenced with the wrong case. The two tools would
 * fight on every run, and the loser would be whoever ran them next.
 *
 * The table is still `resources` and the module is still `Resources`; only the
 * PHP class carries the qualifier.
 *
 * ## Capacity
 *
 * How many uses it supports AT ONCE. A private treatment room is 1; a hammam
 * that seats four is 4. Assuming exclusivity everywhere would make a shared
 * space bookable by one person, which is the opposite of what it is for
 * (docs/13-ROADMAP.md Phase 7 §4).
 *
 * Capacity is checked as PEAK simultaneous load, never as a sum of everything
 * overlapping — see `Kernel\Time\Occupancy`, which explains why the obvious
 * implementation refuses valid bookings.
 *
 * ## One branch, always
 *
 * `branch_id` is NOT NULL. A resource is a physical object standing in one
 * place, and the branch-row lock that serialises booking can only protect
 * resources that belong to the branch being locked (ADR-047).
 *
 * @property int $id
 * @property string $uuid
 * @property int $resource_type_id
 * @property int $branch_id
 * @property int|null $department_id
 * @property TranslatedText $name
 * @property int $capacity
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class OperationalResource extends Model
{
    use UsesTenantConnection;

    protected $table = 'resources';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'capacity' => 'integer',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $resource): void {
            $resource->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<ResourceType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class, 'resource_type_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @param  Builder<OperationalResource>  $query
     * @return Builder<OperationalResource>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Bookable ones at a branch, in the order the allocator will consider them.
     *
     * `sort_order` then `id` — stable, so the same request twice produces the
     * same room. An idempotent retry that quietly moved the customer to a
     * different chair would contradict the confirmation they already have
     * (Phase 7 §11).
     *
     * @param  Builder<OperationalResource>  $query
     * @return Builder<OperationalResource>
     */
    public function scopeBookableAt(Builder $query, int $branchId): Builder
    {
        return $query->active()->where('branch_id', $branchId);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * May a NEW booking take this resource?
     *
     * Existing reservations are unaffected by the answer: deactivating a
     * resource stops the next booking and never touches the ones already made
     * (Phase 7 §12).
     */
    public function isBookable(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }
}
