<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Application\Actions\TransitionAppointment;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * Which buttons this viewer should see on this appointment, right now.
 *
 * A READ, not a gate. Every Action still checks its own permission, branch,
 * entitlement and transition under its own rules, and a button shown here can
 * still be refused there. What this class buys is that a screen never offers
 * something the engine will certainly refuse — and that the rules it uses to
 * decide are the engine's own rather than a second copy in a template
 * (docs/15-BOOKING.md §§9, 14):
 *
 *  - a status change needs the lifecycle to allow it ({@see AppointmentStatus::allowedTransitions()})
 *    AND the permission {@see TransitionAppointment} maps that target to;
 *  - a no-show additionally needs the appointment to have STARTED (§9) —
 *    reported separately as `no_show_later`, so a screen can explain the wait
 *    instead of hiding the button without a word;
 *  - moving, re-assigning a person or a room and a new verification code need `appointment.update`
 *    and an appointment that is still open;
 *  - everything that WRITES needs the `booking` entitlement: after a downgrade
 *    the book stays readable and nothing else (§14);
 *  - and all of it needs the appointment's branch to be in the viewer's scope.
 */
final class AppointmentActions
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @return array{
     *     confirm: bool,
     *     complete: bool,
     *     no_show: bool,
     *     no_show_later: bool,
     *     cancel: bool,
     *     reschedule: bool,
     *     reassign: bool,
     *     change_room: bool,
     *     issue_code: bool,
     *     view_notes: bool,
     *     manage_notes: bool,
     *     writable: bool
     * }
     */
    public function for(Appointment $appointment, User $viewer, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $writable = $this->entitlements->enabled('booking')
            && $viewer->canAccessBranch((int) $appointment->branch_id);

        $status = $appointment->status;
        $open = ! $status->isTerminal();

        $can = fn (AppointmentStatus $target): bool => $writable
            && $status->canTransitionTo($target)
            && $viewer->hasPermission($this->permissionFor($target));

        $noShowAllowed = $can(AppointmentStatus::NoShow);
        $started = $appointment->hasStarted($now);
        $update = $writable && $open && $viewer->hasPermission(Permission::AppointmentUpdate);

        return [
            'confirm' => $can(AppointmentStatus::Confirmed),
            'complete' => $can(AppointmentStatus::Completed),
            'no_show' => $noShowAllowed && $started,
            'no_show_later' => $noShowAllowed && ! $started,
            'cancel' => $can(AppointmentStatus::Cancelled),
            'reschedule' => $update,
            'reassign' => $update,
            'change_room' => $update,
            'issue_code' => $update,
            'view_notes' => $viewer->hasPermission(Permission::AppointmentNoteView),
            'manage_notes' => $writable && $viewer->hasPermission(Permission::AppointmentNoteManage),
            'writable' => $writable,
        ];
    }

    /**
     * The same map {@see TransitionAppointment} authorises with. Kept in step
     * by `AppointmentActionsTest`, which drives both.
     */
    private function permissionFor(AppointmentStatus $target): Permission
    {
        return match ($target) {
            AppointmentStatus::Confirmed => Permission::AppointmentConfirm,
            AppointmentStatus::Completed => Permission::AppointmentComplete,
            AppointmentStatus::NoShow => Permission::AppointmentNoShow,
            AppointmentStatus::Cancelled => Permission::AppointmentCancel,
            AppointmentStatus::Booked => Permission::AppointmentUpdate,
        };
    }
}
