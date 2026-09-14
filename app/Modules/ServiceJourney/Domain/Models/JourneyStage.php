<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Notes\Concerns\HasInternalNotes;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One service of a visit, as it was actually performed.
 *
 * ## Actual, against the item's planned
 *
 *     appointment_items.employee_id   who was BOOKED
 *     journey_stages.employee_id      who actually DID IT
 *
 *     appointment_items.starts_at     when it was PLANNED to run
 *     journey_stages.service_started_at   when it actually STARTED
 *
 * Booked with Ahmed, transferred to Sara halfway through the afternoon: the
 * item still says Ahmed, because that is what the customer was promised and
 * what the confirmation said, and the stage says Sara, because that is who
 * should be credited with the work. Overwriting the item would destroy the
 * planned/actual comparison every later report is built on
 * (docs/13-ROADMAP.md Phase 7 §§18, 24, 27).
 *
 * ## Department, not category
 *
 * Routing is operational, so it follows the service's DEPARTMENT — Hair, Laser,
 * Hammam. Menu categories group services for a customer browsing a price list
 * and route nothing (ADR-037, §22). Snapshotted at check-in, so re-organising
 * the center later does not rewrite what happened.
 *
 * ## A walk-in stage has no booked item
 *
 * Phase 8 made `appointment_item_id` nullable. A walk-in was never reserved, so
 * there is nothing to point at — and inventing an appointment item to satisfy
 * the column would put a reservation into the booking tables for a reservation
 * nobody made.
 *
 *     booked stage    appointment_item_id set, snapshot columns null
 *     walk-in stage   appointment_item_id null, service_id + snapshots set
 *
 * The snapshots are there for the same reason `appointment_items` snapshots:
 * renaming or repricing a service must never rewrite what happened. And
 * `duration_minutes` is not decoration — it is the expected-use window the
 * resource admission check runs against (ADR-050).
 *
 * {@see serviceId()}, {@see durationMinutes()} and {@see serviceName()} hide
 * the difference, so no Action has to branch on which kind of stage it holds
 * (docs/16-JOURNEY-RESOURCES.md §22).
 *
 * @property int $id
 * @property string $uuid
 * @property int $service_journey_id
 * @property int|null $appointment_item_id
 * @property int|null $service_id
 * @property TranslatedText|null $service_name
 * @property int|null $duration_minutes
 * @property int|null $price_minor
 * @property string|null $currency
 * @property int $position
 * @property int|null $department_id
 * @property int|null $employee_id
 * @property StageStatus $status
 * @property Carbon|null $waiting_started_at
 * @property Carbon|null $service_started_at
 * @property Carbon|null $service_completed_at
 * @property string|null $skip_reason
 */
final class JourneyStage extends Model
{
    use HasInternalNotes;
    use UsesTenantConnection;

    protected $table = 'journey_stages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StageStatus::class,
            'service_name' => Translatable::class,
            'waiting_started_at' => 'datetime',
            'service_started_at' => 'datetime',
            'service_completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $stage): void {
            $stage->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Operational notes: "move to room 3", "needs ten minutes before the next
     * service". Internal, never customer-facing (§29).
     */
    public function noteOwnerType(): NoteOwner
    {
        return NoteOwner::JourneyStage;
    }

    /**
     * @return BelongsTo<ServiceJourney, $this>
     */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(ServiceJourney::class, 'service_journey_id');
    }

    /**
     * What was booked. Read, never written, from here.
     *
     * @return BelongsTo<AppointmentItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(AppointmentItem::class, 'appointment_item_id');
    }

    /**
     * The walk-in service. Null on a booked stage, which reaches it through the
     * item — use {@see serviceId()} rather than branching.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Which service this stage is, booked or not.
     *
     * Employee eligibility, resource requirements and the board all need it,
     * and none of them should care how the visit began.
     */
    public function serviceId(): ?int
    {
        $id = $this->service_id ?? $this->item?->service_id;

        return $id === null ? null : (int) $id;
    }

    /**
     * How long this stage is expected to take.
     *
     * THE ADMISSION WINDOW, and the reason the snapshot exists: the combined
     * capacity check asks "is this resource free from now until the service
     * should be done", and a walk-in with no duration would be admitted against
     * a one-minute window and walk into somebody else's room (ADR-050).
     *
     * Never zero: `max(1, …)` is applied by the callers, and a null falls back
     * to a minimal window rather than a `TimeWindow` that refuses to exist.
     */
    public function durationMinutes(): int
    {
        $minutes = $this->duration_minutes ?? $this->item?->duration_minutes;

        return max(1, (int) $minutes);
    }

    public function serviceName(): ?TranslatedText
    {
        return $this->service_name ?? $this->item?->service_name;
    }

    public function isWalkIn(): bool
    {
        return $this->appointment_item_id === null;
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The employee who actually performed it.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Actual resource usage, including anything swapped out mid-service.
     *
     * @return HasMany<JourneyStageResource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(JourneyStageResource::class)->orderBy('assigned_at')->orderBy('id');
    }

    /**
     * The usages still open — what this stage is holding right now.
     *
     * @return HasMany<JourneyStageResource, $this>
     */
    public function openResources(): HasMany
    {
        return $this->resources()->whereNull('released_at');
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
