<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Resources\Domain\Models\OperationalResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Where a called customer is told to go.
 *
 * "Reception Desk 1", "Gate A", "Laser Room 2", "Chair 5".
 *
 * ## A destination is not a resource
 *
 * Sometimes it happens to be one, and `resource_id` records that. Often it is
 * not: nothing reserves a reception counter. The link is OPTIONAL and it is
 * one-directional — calling a ticket here assigns NOTHING. Actual resource
 * capacity is taken only by the Phase 7 Journey Actions, under the branch lock,
 * against the combined occupancy check (docs/17-QUEUE.md §8, ADR-050).
 *
 * A polymorphic "anything can be a destination" was rejected: every consumer
 * would have to know which kind it was holding, and the television needs one
 * thing — a short code and a name.
 *
 * ## Display code, separate from the name
 *
 * The name is translated and can be long. `display_code` is what fits beside a
 * number on a screen read from across a room, and it is unique per branch
 * because two "R1"s make a call ambiguous at the exact moment it has to be
 * obvious.
 *
 * @property int $id
 * @property string $uuid
 * @property int $branch_id
 * @property int|null $department_id
 * @property TranslatedText $name
 * @property string $display_code
 * @property string|null $ticket_prefix
 * @property int|null $resource_id
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class QueueServicePoint extends Model
{
    use UsesTenantConnection;

    protected $table = 'queue_service_points';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $point): void {
            $point->uuid ??= (string) Str::uuid();
        });
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
     * The room or device this counter IS, when it is one. Usually null.
     *
     * @return BelongsTo<OperationalResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(OperationalResource::class, 'resource_id');
    }

    /**
     * @param  Builder<QueueServicePoint>  $query
     * @return Builder<QueueServicePoint>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }
}
