<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Kernel\Time\TimeWindow;
use App\Modules\Resources\Domain\Models\OperationalResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stretch of time during which one stage actually used one resource.
 *
 * ## Intervals, not associations
 *
 * The naive shape — a `resource_id` on the stage, overwritten on a swap — can
 * only answer "which machine is it on now". Phase 7 explicitly allows swapping
 * a device mid-service, and the interesting question afterwards is:
 *
 *     Laser Machine 1   10:00 → 10:15
 *     Laser Machine 2   10:15 → 10:40
 *
 * A swap therefore CLOSES the open row (`released_at = now`) and OPENS a new
 * one. Nothing is overwritten, so device utilisation, fault investigation and
 * "what was this customer treated with" all have an answer
 * (docs/13-ROADMAP.md Phase 7 corrections §5).
 *
 * ## Actual, against the booking's planned
 *
 * `resource_reservations` says what the booking reserved. These rows say what
 * was used. A swap never rewrites the reservation — pretending the booking had
 * always been for Machine 2 would erase the fact that something went wrong with
 * Machine 1 (§26, §36).
 *
 * ## Not an audit trail
 *
 * The audit log records that a swap happened, for security and accountability.
 * This table is operational state the product reads: capacity checks consult
 * it, and a report will group by it. Audit rows are not a source of operational
 * truth (§41).
 *
 * @property int $id
 * @property int $journey_stage_id
 * @property int $resource_id
 * @property int $quantity
 * @property Carbon $assigned_at
 * @property Carbon|null $released_at
 * @property string|null $release_reason
 * @property int|null $assigned_by_user_id
 */
final class JourneyStageResource extends Model
{
    use UsesTenantConnection;

    protected $table = 'journey_stage_resources';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'assigned_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JourneyStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(JourneyStage::class, 'journey_stage_id');
    }

    /**
     * @return BelongsTo<OperationalResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(OperationalResource::class, 'resource_id');
    }

    /**
     * @param  Builder<JourneyStageResource>  $query
     * @return Builder<JourneyStageResource>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function isOpen(): bool
    {
        return $this->released_at === null;
    }

    /**
     * The interval this usage covers, with an open one running to $until.
     *
     * An open usage has no end yet, and a capacity check has to assume it lasts
     * at least as long as the stage was planned to — treating "still in use" as
     * "zero length" would hand the same room to somebody else while a customer
     * is sitting in it.
     */
    public function windowUntil(CarbonImmutable $until): ?TimeWindow
    {
        $start = CarbonImmutable::parse($this->assigned_at, 'UTC');

        $end = $this->released_at === null
            ? $until
            : CarbonImmutable::parse($this->released_at, 'UTC');

        return $end > $start ? new TimeWindow($start, $end) : null;
    }
}
