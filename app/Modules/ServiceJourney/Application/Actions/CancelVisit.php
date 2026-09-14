<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application\Actions;

use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Application\Actions\TransitionAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Calls off a visit that has already started, on both sides.
 *
 * The customer was checked in, some of the work may have begun, and now they
 * are leaving. Two records describe that, and both have to end:
 *
 *   1. the JOURNEY is abandoned — the operational visit stopped, the rooms are
 *      released, and the board stops showing somebody who has gone home;
 *   2. the APPOINTMENT is cancelled — through the Booking lifecycle Action,
 *      which is the only implementation of that transition.
 *
 * ## Why this exists as its own Action
 *
 * Cancelling from the Booking side alone leaves a journey `active` for ever —
 * dangling operational state that nothing clears (Phase 7 corrections §4).
 * Aborting from the Journey side alone leaves the appointment looking like it
 * is still going to happen. Neither module can fix that on its own without
 * reaching into the other's lifecycle, which is exactly what the boundary
 * forbids.
 *
 * So the orchestration lives HERE, in the module that is allowed to depend on
 * Booking, and it calls two Actions rather than writing either module's tables
 * (docs/13-ROADMAP.md Phase 7 §§28, 55).
 *
 * ## Not one transaction, and deliberately
 *
 * Each Action owns its own transaction and its own audit entry. Wrapping both
 * in a third would nest transactions across a module boundary and make a
 * booking rule — "this appointment is already completed" — able to roll back an
 * operational fact that is true regardless. The order is chosen so the worse
 * half-state cannot happen: the journey ends first, so a failure afterwards
 * leaves an ended visit with a live appointment, which reception can see and
 * cancel. The reverse would leave an invisible customer in the building.
 */
final class CancelVisit
{
    public function __construct(
        private readonly AbortJourney $abort,
        private readonly TransitionAppointment $appointments,
    ) {}

    /**
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        Appointment $appointment,
        User $actingUser,
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): Appointment {
        $now ??= CarbonImmutable::now();

        $journey = ServiceJourney::query()
            ->with(['stages', 'appointment'])
            ->where('appointment_id', $appointment->getKey())
            ->first();

        // Only an active journey needs ending. A visit that already completed
        // and is now being cancelled for a billing reason keeps its operational
        // record — it did happen.
        if ($journey instanceof ServiceJourney && $journey->status === JourneyStatus::Active) {
            ($this->abort)($journey, $actingUser, $reason, $now);
        }

        return ($this->appointments)(
            $appointment,
            AppointmentStatus::Cancelled,
            BookingActor::staff($actingUser),
            ['reason' => $reason],
            $now,
        );
    }
}
