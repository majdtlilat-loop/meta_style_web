<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain\Models;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Kernel\Time\TimeWindow;
use App\Modules\Employees\Domain\Enums\AvailabilityBlockType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A stretch of time an employee cannot be booked at a branch.
 *
 * The Phase 6 Availability Engine knew about appointments and nothing else, so
 * a stylist's lunch hour was bookable. This is the smallest thing that fixes
 * that (docs/13-ROADMAP.md Phase 7 §13).
 *
 * NOT attendance. No clock-in, no worked hours, no leave balance, no approval
 * workflow. When a real attendance module arrives it becomes a SECOND source
 * answering the same question, joining this one behind the Booking Engine's
 * block finder — the engine itself does not change (§45).
 *
 * `branch_id` is required: availability is a per-branch question, and a
 * nullable branch would leave the coordination lock with no single row to take
 * (ADR-047). Blocking somebody across three branches is three rows.
 *
 * @property int $id
 * @property string $uuid
 * @property int $employee_id
 * @property int $branch_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property AvailabilityBlockType $type
 * @property string|null $internal_note
 * @property int|null $created_by_user_id
 */
final class EmployeeAvailabilityBlock extends Model
{
    use UsesTenantConnection;

    protected $table = 'employee_availability_blocks';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'type' => AvailabilityBlockType::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $block): void {
            $block->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function window(): TimeWindow
    {
        return new TimeWindow(
            CarbonImmutable::parse($this->starts_at, 'UTC'),
            CarbonImmutable::parse($this->ends_at, 'UTC'),
        );
    }

    /**
     * Blocks overlapping a window, by the one overlap rule.
     *
     *     block.starts_at < candidate.ends_at AND block.ends_at > candidate.starts_at
     *
     * Strict both sides, so a break ending at 13:00 does not block a 13:00
     * appointment — the same boundary `TimeWindow` and `ConflictFinder` use. If
     * one changes, all three must.
     *
     * @param  Builder<EmployeeAvailabilityBlock>  $query
     * @return Builder<EmployeeAvailabilityBlock>
     */
    public function scopeOverlapping(Builder $query, Carbon|CarbonImmutable $from, Carbon|CarbonImmutable $to): Builder
    {
        return $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }
}
