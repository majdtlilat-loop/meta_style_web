<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Application\Actions\RescheduleAppointment;
use App\Modules\Booking\Application\Actions\TransitionAppointment;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\BookingResult;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * The Booking Engine, assembled from its Actions.
 *
 * A thin facade on purpose. Each Action owns its own transaction, entitlement
 * check, authorization and audit entry — this class exists so that a channel
 * depends on {@see BookingEngine} rather than on five constructor arguments,
 * and so a future adapter has one thing to inject.
 *
 * NO LOGIC LIVES HERE. Anything added to this class would be a rule that the
 * Actions do not enforce, which means a caller reaching an Action directly
 * would bypass it — and the Actions are public precisely so tests and console
 * commands can. If a rule belongs to booking, it belongs in the Action.
 */
final class MetaStyleBookingEngine implements BookingEngine
{
    public function __construct(
        private readonly AvailabilityEngine $availability,
        private readonly CreateAppointment $create,
        private readonly RescheduleAppointment $rescheduleAction,
        private readonly TransitionAppointment $transitionAction,
    ) {}

    /**
     * @return list<AvailabilitySlot>
     */
    public function availability(AvailabilityQuery $query, bool $publicChannel = false): array
    {
        return $this->availability->slots($query, $publicChannel);
    }

    public function book(BookingRequest $request, BookingActor $actor): BookingResult
    {
        return ($this->create)($request, $actor);
    }

    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, BookingActor $actor): Appointment
    {
        return ($this->rescheduleAction)($appointment, $startsAt, $actor);
    }

    public function cancel(Appointment $appointment, BookingActor $actor, ?string $reason = null): Appointment
    {
        return ($this->transitionAction)(
            $appointment,
            AppointmentStatus::Cancelled,
            $actor,
            ['reason' => $reason],
        );
    }

    public function transition(Appointment $appointment, AppointmentStatus $target, BookingActor $actor): Appointment
    {
        return ($this->transitionAction)($appointment, $target, $actor);
    }
}
