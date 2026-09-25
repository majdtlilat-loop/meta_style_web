<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Models;

use App\Kernel\Money\Money;
use App\Kernel\Notes\Concerns\HasInternalNotes;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Kernel\Time\BranchClock;
use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Enums\BookingSource;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Domain\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A customer's reservation at a branch.
 *
 * The appointment is the VISIT; the services are {@see AppointmentItem} rows
 * beneath it. That split is what lets one arrival by one person carry three
 * services and still be one thing to reception, to the queue and to the
 * invoice (docs/13-ROADMAP.md Phase 6 §2).
 *
 * NOTHING HERE MUTATES STATE. Creating, rescheduling, cancelling and every
 * status change go through the Booking Engine's Actions, because each of them
 * needs a lock, an availability re-check, a transaction and an audit entry that
 * a model method could not honestly provide. A `$appointment->cancel()` helper
 * would be the obvious place for a channel to bypass all four (§40).
 *
 * THE VERIFICATION CODE IS NOT GENERATED HERE. There is no `created` hook that
 * mints one, deliberately: a one-time secret must be handed back through the
 * return value of the call that created the booking, and a model event has
 * nowhere to return anything to. The `CreateAppointment` Action generates it
 * explicitly and a `BookingResult` carries it out
 * (docs/24-BOOKING-VERIFICATION.md §3). What lives here is only the storage.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $reference
 * @property string|null $verification_code_digest
 * @property string|null $verification_code_key_version
 * @property CarbonImmutable|null $verification_code_issued_at
 * @property int $customer_id
 * @property int $branch_id
 * @property AppointmentStatus $status
 * @property BookingSource $source
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $booked_timezone
 * @property string|null $customer_note
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $no_show_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_from_status
 * @property string|null $cancellation_reason
 * @property string|null $cancelled_by_type
 * @property string|null $cancelled_by_id
 * @property string|null $cancelled_by_label
 * @property string $created_by_type
 * @property string|null $created_by_id
 * @property string|null $created_by_label
 */
final class Appointment extends Model
{
    use HasInternalNotes;
    use UsesTenantConnection;

    protected $table = 'appointments';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'source' => BookingSource::class,
            // Immutable, so a `->addMinutes()` somewhere cannot quietly move a
            // booking that another line of code is still holding a reference to.
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'no_show_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'verification_code_issued_at' => 'immutable_datetime',
        ];
    }

    /**
     * The digest is never serialised. It is not a password — it cannot be used
     * to impersonate anybody by itself — but it is the stored half of a
     * capability, and an API response or a Livewire payload is no place for it
     * (docs/24-BOOKING-VERIFICATION.md §11).
     *
     * @var list<string>
     */
    protected $hidden = ['verification_code_digest'];

    protected static function booted(): void
    {
        self::creating(function (self $appointment): void {
            $appointment->uuid ??= (string) Str::uuid();
        });
    }

    public function noteOwnerType(): NoteOwner
    {
        return NoteOwner::Appointment;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<AppointmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class)->orderBy('position')->orderBy('id');
    }

    // ------------------------------------------------------------------ time

    public function window(): TimeWindow
    {
        return new TimeWindow($this->starts_at->utc(), $this->ends_at->utc());
    }

    /**
     * The wall clock the customer was told, in the zone that was in force when
     * they were told it.
     */
    public function localStart(): CarbonImmutable
    {
        return BranchClock::toLocal($this->starts_at->utc(), $this->booked_timezone);
    }

    public function localEnd(): CarbonImmutable
    {
        return BranchClock::toLocal($this->ends_at->utc(), $this->booked_timezone);
    }

    public function localDate(): string
    {
        return BranchClock::localDate($this->starts_at->utc(), $this->booked_timezone);
    }

    // ----------------------------------------------------------------- state

    public function isCancelled(): bool
    {
        return $this->status === AppointmentStatus::Cancelled;
    }

    /**
     * Has a verification capability ever been issued for this booking?
     *
     * False for every appointment made before Phase 13: those were deliberately
     * NOT given codes in bulk, because minting a live secret for a booking
     * nobody asked about creates a credential with no owner
     * (docs/24-BOOKING-VERIFICATION.md §10). Staff issue one on request.
     */
    public function hasVerificationCode(): bool
    {
        return $this->verification_code_digest !== null;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Does this appointment still occupy its slot?
     */
    public function blocksTime(): bool
    {
        return in_array($this->status, AppointmentStatus::blocking(), true);
    }

    public function hasStarted(?CarbonImmutable $now = null): bool
    {
        return $this->starts_at->utc() <= ($now ?? CarbonImmutable::now())->utc();
    }

    /**
     * The money for the whole visit, in minor units.
     *
     * From the snapshots, never from the live catalog — that is the entire
     * point of taking them (§3). Returned as an integer rather than a
     * {@see Money} because items could in principle carry
     * different snapshot currencies after a center switches, and silently
     * adding those would be exactly the bug Money exists to prevent. Callers
     * that need a Money build one from `currencyCode()`.
     */
    public function totalMinor(): int
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return (int) $items->sum('price_minor');
    }

    public function currencyCode(): ?string
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        /** @var string|null $currency */
        $currency = $items->first()?->currency;

        return $currency;
    }

    // --------------------------------------------------------------- queries

    /**
     * Appointments that still hold their slot.
     *
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', AppointmentStatus::blockingValues());
    }

    /**
     * Appointments starting inside an absolute window.
     *
     * Takes UTC instants, because a "day" is a branch-local question that the
     * caller has already answered with {@see BranchClock}. A scope that took a
     * date string would have to guess a timezone.
     *
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeStartingBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->utc());
    }
}
