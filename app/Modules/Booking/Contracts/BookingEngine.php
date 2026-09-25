<?php

declare(strict_types=1);

namespace App\Modules\Booking\Contracts;

use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\BookingResult;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * THE booking contract. Five methods, one implementation, every channel.
 *
 * ```
 *   Public web   Customer app   Host UI   White label   WhatsApp   RAYAN
 *        └────────────┴────────────┴──────────┴────────────┴─────────┘
 *                                  │
 *                            BookingEngine
 * ```
 *
 * A channel may parse its own input, resolve its own customer, present
 * availability and call these methods. A channel may NOT compute a slot, decide
 * whether a booking is allowed, apply a policy, or write to `appointments`.
 * That boundary is the reason a WhatsApp bot cannot invent its own idea of
 * whether a stylist is free (docs/04-MODULE-BOUNDARIES.md §4.1).
 *
 * ## Why an interface at all, with one implementation
 *
 * The project's own rule is that speculative abstractions are worse than
 * nothing, and normally a single implementation would just be a class. This is
 * the documented exception: the interface is the STATEMENT OF THE BOUNDARY.
 * Phases 8, 9, 13 and 14 are adapters written against it, and a future channel
 * author reads this file to find out what they are allowed to call. A concrete
 * class with fifteen public methods would not say that.
 *
 * ## Deliberately absent
 *
 * `quote()` — the roadmap's fifth verb — needs deposits, cancellation fees and
 * a payment provider, none of which exist. Price is already knowable from
 * availability plus the catalog, so a quote method today would return the sum
 * of the snapshot prices and call itself a quote. It arrives with Payments.
 */
interface BookingEngine
{
    /**
     * Bookable start times for a branch, a service set and a date range.
     *
     * ADVISORY. Two callers can be shown the same slot; only {@see book()}
     * decides. `$publicChannel` narrows the answer to what a guest may see —
     * public branches, online-bookable services.
     *
     * @return list<AvailabilitySlot>
     *
     * @throws BookingFailed
     */
    public function availability(AvailabilityQuery $query, bool $publicChannel = false): array;

    /**
     * Creates an appointment, or refuses.
     *
     * The authoritative availability check happens inside this call, under a
     * lock. A slot returned by {@see availability()} a moment ago can still be
     * refused here, and that is correct behaviour rather than a race.
     *
     * ## Why this one returns a result object
     *
     * Every new booking is given a VERIFICATION CODE — the short secret that
     * later proves somebody holds this booking — and that code is never stored
     * raw. There is therefore no later read that could produce it: the return
     * of this call is the only moment it exists, so the return carries it
     * (docs/24-BOOKING-VERIFICATION.md §3).
     *
     * A channel is free to ignore `$result->verificationCode`; WhatsApp does,
     * deliberately (§12). What a channel may not do is assume it can fetch the
     * code afterwards.
     *
     * @throws BookingFailed
     */
    public function book(BookingRequest $request, BookingActor $actor): BookingResult;

    /**
     * Moves an appointment, with the same validation and locking as booking it.
     *
     * @throws BookingFailed
     */
    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, BookingActor $actor): Appointment;

    /**
     * Cancels an appointment, recording who and why.
     *
     * @throws BookingFailed
     */
    public function cancel(Appointment $appointment, BookingActor $actor, ?string $reason = null): Appointment;

    /**
     * Any other status change: confirm, complete, no-show.
     *
     * Invalid moves are refused by the lifecycle, not by the caller
     * remembering to check.
     *
     * @throws BookingFailed
     */
    public function transition(Appointment $appointment, AppointmentStatus $target, BookingActor $actor): Appointment;
}
