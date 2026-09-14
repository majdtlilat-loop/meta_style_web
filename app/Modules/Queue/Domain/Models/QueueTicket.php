<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One waiting, callable unit of work: a number, and where to send it.
 *
 * ## It is not the source of truth for anything a service did
 *
 *     Appointment     what was reserved
 *     ServiceJourney  the operational visit
 *     JourneyStage    what was actually performed
 *     QueueTicket     the waiting, calling and routing around that stage
 *
 * Four concepts, permanently separate. `serving_started_at` here is queue
 * METADATA — it records when the queue learned the stage had started, reported
 * by Journey. The service itself began at `journey_stages.service_started_at`,
 * and a report asking "how long did the haircut take" reads that one
 * (docs/17-QUEUE.md §1, §5).
 *
 * ## Almost no customer or service data
 *
 * Everything about the person is one relation away through the journey. A copy
 * here would be a copy on the read model a PUBLIC SCREEN is built from, which
 * is the last place it belongs (§14).
 *
 * The exceptions are deliberate: `display_number` is snapshotted because it is
 * printed on paper in somebody's hand, and `last_announcement_uuid` points at
 * the call a screen is currently speaking so a poll every three seconds does
 * not repeat itself (§13).
 *
 * ## One open ticket per stage
 *
 * `active_journey_stage_id` mirrors `journey_stage_id` while the ticket is open
 * and is NULL once it closes. A unique index on it means a double-clicked
 * "issue ticket" collides in the database rather than in somebody's good
 * intentions, while history still accumulates freely (§11).
 *
 * @property int $id
 * @property string $uuid
 * @property int $branch_id
 * @property int $service_journey_id
 * @property int $journey_stage_id
 * @property int|null $active_journey_stage_id
 * @property int|null $department_id
 * @property int|null $service_point_id
 * @property Carbon $business_date
 * @property string $prefix
 * @property int $number
 * @property string $display_number
 * @property int $priority
 * @property TicketState $state
 * @property Carbon $issued_at
 * @property Carbon|null $first_called_at
 * @property Carbon|null $last_called_at
 * @property Carbon|null $held_at
 * @property Carbon|null $serving_started_at
 * @property Carbon|null $closed_at
 * @property int $call_count
 * @property int $skip_count
 * @property string|null $hold_reason
 * @property string|null $close_reason
 * @property string|null $last_announcement_uuid
 * @property string $source
 */
final class QueueTicket extends Model
{
    use UsesTenantConnection;

    protected $table = 'queue_tickets';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => TicketState::class,
            'business_date' => 'date',
            'issued_at' => 'datetime',
            'first_called_at' => 'datetime',
            'last_called_at' => 'datetime',
            'held_at' => 'datetime',
            'serving_started_at' => 'datetime',
            'closed_at' => 'datetime',
            'priority' => 'integer',
            'number' => 'integer',
            'call_count' => 'integer',
            'skip_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $ticket): void {
            $ticket->uuid ??= (string) Str::uuid();
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
     * @return BelongsTo<ServiceJourney, $this>
     */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(ServiceJourney::class, 'service_journey_id');
    }

    /**
     * The stage this ticket is the waiting mechanism for.
     *
     * READ, never written from here. An architecture test scans this module for
     * writes to journey tables (§12).
     *
     * @return BelongsTo<JourneyStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(JourneyStage::class, 'journey_stage_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<QueueServicePoint, $this>
     */
    public function servicePoint(): BelongsTo
    {
        return $this->belongsTo(QueueServicePoint::class, 'service_point_id');
    }

    /**
     * @return HasMany<QueueTicketEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(QueueTicketEvent::class)->orderBy('sequence');
    }

    /**
     * THE ordering, in one place.
     *
     * Priority first, because that is what changes who is next. Then the moment
     * it was issued, which keeps FIFO inside a priority — a held ticket that
     * resumes keeps its ORIGINAL `issued_at` and therefore its place, rather
     * than going to the back for having stepped outside. Then the id, so a tie
     * is decided by something rather than by whatever order the engine felt
     * like returning (§10, §20).
     *
     * @param  Builder<QueueTicket>  $query
     * @return Builder<QueueTicket>
     */
    public function scopeInCallOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('priority')
            ->orderBy('issued_at')
            ->orderBy('id');
    }

    /**
     * @param  Builder<QueueTicket>  $query
     * @return Builder<QueueTicket>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('state', TicketState::openValues());
    }

    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    /**
     * How long the customer waited before the first call, in minutes.
     *
     * Derived, never stored. A column would be a fourth place the truth lives
     * and the first to go stale — and Phase 8 computes no report aggregates
     * (§17, §38).
     */
    public function waitedMinutes(): ?int
    {
        if ($this->first_called_at === null) {
            return null;
        }

        return (int) $this->issued_at->diffInMinutes($this->first_called_at);
    }
}
