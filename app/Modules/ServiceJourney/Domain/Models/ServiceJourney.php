<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\ServiceJourney\Domain\Enums\JourneySource;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One customer's visit, as it actually happened.
 *
 * ## The distinction the whole phase rests on
 *
 *     Appointment      what was RESERVED — 10:00, Ahmed, thirty minutes
 *     ServiceJourney   what HAPPENED     — arrived 10:12, seen by Sara, out at 10:51
 *
 * Both are true. Neither overwrites the other, and every delay report, duration
 * variance and employee attribution a later phase wants needs both
 * (docs/13-ROADMAP.md Phase 7 §§15, 27).
 *
 * ## It begins at the door, not at booking
 *
 * A journey is created when somebody checks the customer in. Creating one for
 * every future booking would fill the operational tables with visits that have
 * not happened and might never, and the board would have to filter them out
 * again (§16).
 *
 * The unique key on `appointment_id` is what makes check-in idempotent: a
 * double-clicked button is a duplicate-key violation the Action turns into
 * "here is the journey that already exists" (§48).
 *
 * ## A booked journey does not carry the customer or the branch
 *
 * Both are reachable through the appointment. Copying them there would be a
 * second place they could disagree, and appointments do not change branch (§15).
 *
 * ## A WALK-IN has no appointment to reach through
 *
 * Phase 8 made `appointment_id` nullable rather than inventing an appointment
 * for somebody who never booked one. A walk-in therefore carries the two facts
 * it can no longer derive, and nothing else:
 *
 *     source = appointment   appointment_id set, customer_id and branch_id null
 *     source = walk_in       appointment_id null, customer_id and branch_id set
 *
 * The invariant is enforced by the Actions and by tests, not by a CHECK
 * constraint the two engines disagree about (ADR-033).
 *
 * `branchId()` and `customerId()` hide the difference from every caller: one
 * answer, one storage rule, and no duplicated column on the booked path
 * (docs/16-JOURNEY-RESOURCES.md §22).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $appointment_id
 * @property int|null $customer_id
 * @property int|null $branch_id
 * @property JourneySource $source
 * @property string|null $idempotency_token
 * @property JourneyStatus $status
 * @property Carbon|null $arrived_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $aborted_at
 * @property string|null $abort_reason
 * @property string $created_by_type
 * @property string|null $created_by_id
 * @property string|null $created_by_label
 */
final class ServiceJourney extends Model
{
    use UsesTenantConnection;

    protected $table = 'service_journeys';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JourneyStatus::class,
            'source' => JourneySource::class,
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'aborted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $journey): void {
            $journey->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * The walk-in customer. Null on a booked journey, which reads it through
     * the appointment — use {@see customerId()} rather than branching here.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The walk-in branch. Null on a booked journey; see {@see branchId()}.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Which branch this visit is happening at, whichever kind of visit it is.
     *
     * THE ONE READER every caller should use. Authorization, the branch lock,
     * the queue's numbering scope and every board query need a branch, and none
     * of them should have to know whether this visit was booked.
     *
     * Returns 0 when neither is available — a journey whose appointment was not
     * loaded. Callers treat 0 as "no branch to check", which is the same
     * conservative reading the Phase 7 Actions already used.
     */
    public function branchId(): int
    {
        if ($this->branch_id !== null) {
            return (int) $this->branch_id;
        }

        $appointment = $this->appointment;

        return $appointment instanceof Appointment ? (int) $appointment->branch_id : 0;
    }

    public function customerId(): int
    {
        if ($this->customer_id !== null) {
            return (int) $this->customer_id;
        }

        $appointment = $this->appointment;

        return $appointment instanceof Appointment ? (int) $appointment->customer_id : 0;
    }

    public function isWalkIn(): bool
    {
        return $this->source === JourneySource::WalkIn;
    }

    /**
     * @return HasMany<JourneyStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(JourneyStage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<JourneyHandoff, $this>
     */
    public function handoffs(): HasMany
    {
        return $this->hasMany(JourneyHandoff::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @param  Builder<ServiceJourney>  $query
     * @return Builder<ServiceJourney>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', JourneyStatus::Active->value);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Has every stage finished one way or another?
     *
     * Skipped counts: a service the customer declined is finished business, and
     * a visit whose only outstanding stage was declined is a visit that is over
     * (§28).
     */
    public function allStagesSettled(): bool
    {
        return ! $this->stages()
            ->whereNotIn('status', StageStatus::settledValues())
            ->exists();
    }
}
