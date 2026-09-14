<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One customer being passed from one part of the center to another.
 *
 * "Ahmed finished the laser session; the customer went to the hammam, where
 * Sara took over." That sentence has six facts in it — from stage, to stage,
 * from employee, to employee, from department, to department — and this row
 * holds all six plus who recorded it and when.
 *
 * ## Append-only, and deliberately small
 *
 * A generic event store would answer this and every other question badly: it
 * would need a projection to read, a schema nothing else in the product uses,
 * and a decision about replay that nobody has made
 * (docs/13-ROADMAP.md Phase 7 §23). One narrow table, one index, one query.
 *
 * Nothing updates or deletes these rows. The handoff history of a visit is the
 * rows in creation order, and a correction is a new row rather than an edit —
 * which is what makes it usable in an investigation.
 *
 * @property int $id
 * @property string $uuid
 * @property int $service_journey_id
 * @property int|null $from_stage_id
 * @property int|null $to_stage_id
 * @property int|null $from_employee_id
 * @property int|null $to_employee_id
 * @property int|null $from_department_id
 * @property int|null $to_department_id
 * @property string|null $note
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_label
 */
final class JourneyHandoff extends Model
{
    use UsesTenantConnection;

    protected $table = 'journey_handoffs';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(function (self $handoff): void {
            $handoff->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<ServiceJourney, $this>
     */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(ServiceJourney::class, 'service_journey_id');
    }

    /**
     * @return BelongsTo<JourneyStage, $this>
     */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(JourneyStage::class, 'from_stage_id');
    }

    /**
     * @return BelongsTo<JourneyStage, $this>
     */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(JourneyStage::class, 'to_stage_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }
}
