<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Enums;

/**
 * Where an appointment is in its life.
 *
 * FIVE STATES, AND DELIBERATELY NOT MORE. `arrived`, `waiting` and `in_service`
 * describe how a visit is EXECUTED, which is Service Journey's job in Phase 7.
 * An appointment says what was reserved; it does not track what is happening in
 * the chair (docs/13-ROADMAP.md Phase 6 §§11, 38).
 *
 * `pending` is also absent. Phase 6 has no deposits and no approval step, so
 * nothing could ever move an appointment out of it — and a state nothing
 * reaches is a promise, not a feature. The transition map below is the only
 * thing that would need editing to add it.
 */
enum AppointmentStatus: string
{
    /** Reserved. The normal state of a new appointment. */
    case Booked = 'booked';

    /** Someone — staff or the customer — has affirmed it is happening. */
    case Confirmed = 'confirmed';

    /** The visit happened. Terminal. */
    case Completed = 'completed';

    /** Called off before it happened. Terminal. */
    case Cancelled = 'cancelled';

    /** The customer did not come. Terminal. */
    case NoShow = 'no_show';

    /**
     * Statuses that still occupy the calendar.
     *
     * THE CONFLICT SET. A cancelled or no-show appointment must not block the
     * slot it used to hold, and a completed one is in the past. Everything that
     * detects a double booking filters on exactly this list.
     *
     * @return list<self>
     */
    public static function blocking(): array
    {
        return [self::Booked, self::Confirmed];
    }

    /**
     * @return list<string>
     */
    public static function blockingValues(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::blocking());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::NoShow => true,
            self::Booked, self::Confirmed => false,
        };
    }

    /**
     * The allowed moves out of this status.
     *
     * `booked → completed` and `booked → no_show` are permitted on purpose. A
     * center that never uses the confirm step — most walk-in barbershops will
     * not — would otherwise be unable to close out a single appointment, and
     * would end up confirming everything mechanically just to satisfy the state
     * machine. That is a workflow the software invented, not one the business
     * has.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Booked => [self::Confirmed, self::Completed, self::Cancelled, self::NoShow],
            self::Confirmed => [self::Completed, self::Cancelled, self::NoShow],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
