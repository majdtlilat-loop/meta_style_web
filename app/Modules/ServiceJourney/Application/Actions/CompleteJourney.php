<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Application\Actions\TransitionAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\ServiceJourney\Application\JourneySnapshot;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Events\JourneyCompleted;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The customer is done and leaving.
 *
 * ## Journey NEVER writes appointment status
 *
 * The appointment's lifecycle has exactly one implementation, and it is
 * {@see TransitionAppointment} in the Booking module. Completing a visit calls
 * it. Writing `appointments.status = 'completed'` from here would be a second
 * lifecycle with its own idea of which transitions are legal, its own audit
 * entry, and its own bugs — and an architecture test fails the build if anybody
 * tries (docs/13-ROADMAP.md Phase 7 §§28, 55).
 *
 * That call is also what keeps the Phase 6 promise intact: completion creates
 * no sale, no invoice, no commission, no loyalty and no review request. Those
 * modules do not exist, and faking their side effects would leave a center's
 * books full of records nothing can reconcile.
 *
 * ## Every stage must be settled
 *
 * Completed or skipped. A visit closed with somebody still in the chair is a
 * board that lies about who is in the building, and the honest fix is to
 * finish or skip the stage first (§28).
 */
final class CompleteJourney
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly TransitionAppointment $appointments,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        ServiceJourney $journey,
        User $actingUser,
        ?CarbonImmutable $now = null,
    ): ServiceJourney {
        $this->entitlements->ensure('booking');

        // UTC, explicitly. "Store UTC, compute in the branch timezone" is the
        // rule (CLAUDE.md), and an Action that stores whichever zone its caller
        // happened to be holding writes a wall clock three hours out without
        // anything failing — the values look plausible and sort wrongly.
        $now = ($now ?? CarbonImmutable::now())->utc();

        /*
         * Loaded here rather than assumed. An Action that relies on its caller
         * having eager-loaded the right relations is an Action that issues a
         * query per row the first time somebody calls it from a loop — and
         * `loadMissing` costs nothing when the caller already did the work.
         */
        $journey->loadMissing(['appointment', 'stages']);

        $this->authorize($journey, $actingUser);

        if (! $journey->status->canTransitionTo(JourneyStatus::Completed)) {
            throw JourneyFailed::invalidTransition(
                "A visit that is {$journey->status->value} cannot be completed.",
                ['from' => $journey->status->value],
            );
        }

        if (! $journey->allStagesSettled()) {
            throw JourneyFailed::policy(
                'Finish or skip every service before closing the visit.',
            );
        }

        $before = JourneySnapshot::of($journey);

        DB::connection('tenant')->transaction(function () use ($journey, $now): void {
            $journey->forceFill([
                'status' => JourneyStatus::Completed,
                'completed_at' => $now,
            ])->save();

            // In the same transaction, so a visit reward commits with the
            // completion. Listeners key on the journey: a repeat cannot pay twice.
            Event::dispatch(new JourneyCompleted((int) $journey->getKey(), $journey->branchId()));
        });

        $journey->refresh()->load('stages');

        $this->audit->record(new AuditEvent(
            action: 'journey.completed',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ServiceJourney::class,
            targetId: $journey->uuid,
            before: $before,
            after: JourneySnapshot::of($journey),
        ));

        $this->completeAppointment($journey, $actingUser, $now);

        return $journey;
    }

    /**
     * Hands the appointment to the Booking lifecycle.
     *
     * Deliberately AFTER the journey's own transaction rather than inside it.
     * The Booking Action opens its own transaction and writes its own audit
     * entry; nesting them would tie the operational record's fate to a booking
     * rule — "this appointment was already cancelled" — that has nothing to do
     * with whether the visit happened.
     *
     * An appointment that cannot be completed leaves the journey completed and
     * says so. That is the honest state: the customer WAS served.
     */
    private function completeAppointment(
        ServiceJourney $journey,
        User $actingUser,
        CarbonImmutable $now,
    ): void {
        $appointment = $journey->appointment;

        if ($appointment === null || ! $appointment->status->canTransitionTo(AppointmentStatus::Completed)) {
            return;
        }

        ($this->appointments)(
            $appointment,
            AppointmentStatus::Completed,
            BookingActor::staff($actingUser),
            [],
            $now,
        );
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(ServiceJourney $journey, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::JourneyManage)) {
            throw new AuthorizationException('You may not manage visits.');
        }

        // Completing the visit completes the appointment, so the caller needs
        // that grant too — the Booking Action would refuse them anyway, and
        // refusing here means the journey is not closed against an appointment
        // that then stays open.
        if (! $actingUser->hasPermission(Permission::AppointmentComplete)) {
            throw new AuthorizationException('You may not complete appointments.');
        }

        $branchId = $journey->branchId();

        if ($branchId > 0 && ! $actingUser->canAccessBranch($branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
