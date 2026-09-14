<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application;

use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonInterface;

/**
 * One line of the operational board: what was booked, and what is happening.
 *
 * ## Why this is a pair and not a relation
 *
 * The obvious shape is `Appointment::journey()`. It is also a boundary
 * violation: Booking must not depend on ServiceJourney, or the reservation
 * system starts knowing about the floor and Phase 8's queue would inherit the
 * same tangle (docs/13-ROADMAP.md Phase 7 §55).
 *
 * So the two halves are fetched separately and paired here — two queries for
 * the whole board rather than one, and the dependency still points one way.
 *
 * A null journey is the normal, common case: it means the customer has not
 * arrived. Absence IS the state (§17).
 *
 * ## A null APPOINTMENT means a walk-in
 *
 * Phase 8 put visits on the board that were never booked. Exactly one of the
 * two halves may be null, never both: no appointment means a walk-in, and no
 * journey means somebody booked and has not arrived
 * (docs/16-JOURNEY-RESOURCES.md §22).
 */
final readonly class BoardRow
{
    public function __construct(
        public ?Appointment $appointment,
        public ?ServiceJourney $journey,
    ) {}

    /**
     * A visit nobody booked.
     */
    public function isWalkIn(): bool
    {
        return $this->appointment === null;
    }

    /**
     * The customer, whichever half of the row carries them.
     *
     * A booked row reads the appointment; a walk-in reads the journey, which is
     * the only place the customer is recorded for a visit that was never
     * reserved.
     */
    public function customer(): ?Customer
    {
        if ($this->appointment instanceof Appointment) {
            return $this->appointment->customer;
        }

        return $this->journey?->customer;
    }

    public function branchId(): int
    {
        if ($this->appointment instanceof Appointment) {
            return (int) $this->appointment->branch_id;
        }

        return $this->journey?->branchId() ?? 0;
    }

    /**
     * When this visit belongs on the board's timeline.
     *
     * The booked start for a reservation, the arrival for a walk-in. They sort
     * together: a 10:00 booking and somebody who walked in at 10:05 appear in
     * the order the desk experienced them.
     */
    public function sortsAt(): ?CarbonInterface
    {
        if ($this->appointment instanceof Appointment) {
            return $this->appointment->starts_at;
        }

        return $this->journey?->arrived_at;
    }

    /**
     * Which column of the board this belongs in.
     *
     * DERIVED, never stored. A `board_column` value would be a fourth place the
     * truth lives and the first to go stale — and a stored operational position
     * is exactly the queue state Phase 7 refuses to invent ahead of Phase 8
     * (§44).
     */
    public function group(): string
    {
        if ($this->journey === null) {
            return 'not_arrived';
        }

        return match ($this->journey->status) {
            JourneyStatus::Completed => 'completed',
            JourneyStatus::Aborted => 'abandoned',
            JourneyStatus::Active => $this->activeGroup(),
        };
    }

    /**
     * The stage currently being performed, if any.
     */
    public function currentStage(): ?JourneyStage
    {
        if ($this->journey === null) {
            return null;
        }

        foreach ($this->journey->stages as $stage) {
            if ($stage->status === StageStatus::InService) {
                return $stage;
            }
        }

        return null;
    }

    private function activeGroup(): string
    {
        return $this->currentStage() instanceof JourneyStage ? 'in_service' : 'waiting';
    }
}
