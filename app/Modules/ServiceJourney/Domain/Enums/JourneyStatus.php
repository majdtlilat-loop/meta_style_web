<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Enums;

/**
 * Where a visit stands operationally.
 *
 * THREE STATES, and there is deliberately no `not_started`: a customer who has
 * not arrived has no journey at all. Modelling "not started" as a row would put
 * a journey against every booking months in advance, and the board would then
 * have to distinguish "not started" from "no journey" — two ways of saying the
 * same thing, one of which somebody will eventually forget to check
 * (docs/13-ROADMAP.md Phase 7 §17).
 *
 * ## Aborted
 *
 * The customer arrived, processing began, and then they left — or the desk
 * called the visit off. Without a terminal operational state such a journey
 * stays `active` for ever while its appointment is cancelled, which is dangling
 * state the board can never clear and every future "who is in the building"
 * query has to special-case (Phase 7 corrections §4).
 *
 * ## This does not mirror the appointment
 *
 * A journey is not a second copy of the appointment's lifecycle. Cancellation
 * lives on the appointment; `aborted` records that the operational visit
 * stopped, which is a different fact and can be true while the appointment is
 * still `booked`. Journey NEVER writes appointment status — the supported flow
 * calls the Booking lifecycle Action, and an architecture test enforces it.
 */
enum JourneyStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Aborted = 'aborted';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Completed, self::Aborted],

            // Terminal. A completed visit cannot be abandoned after the fact,
            // and an abandoned one cannot be restarted — the customer would be
            // checked in again, which creates a new journey honestly rather
            // than reviving an old one.
            self::Completed, self::Aborted => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => __('In progress'),
            self::Completed => __('Completed'),
            self::Aborted => __('Abandoned'),
        };
    }
}
