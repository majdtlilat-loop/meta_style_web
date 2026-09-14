<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Actions;

use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Modules\Booking\Domain\BookingSettings;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Every status change an appointment can undergo: confirm, complete, cancel,
 * no-show.
 *
 * ONE ACTION, NOT FOUR. They differ in exactly three things — the permission
 * required, the timestamp stamped, and one extra rule apiece — and four
 * near-identical classes would be four places to forget the transition check.
 * The state machine itself lives in {@see AppointmentStatus}, so an invalid move
 * is refused by the enum rather than by whoever remembered to write the `if`.
 *
 * ## Completion does not do anything financial
 *
 * Marking an appointment completed records that the visit happened. It creates
 * no sale, no invoice, no commission, no loyalty points and no review request.
 * Those modules do not exist, and faking their side effects — an invoice row
 * with no POS behind it — would leave a center's books full of records nothing
 * can reconcile. Later phases will react to completion; that is what the audit
 * event is for (docs/13-ROADMAP.md Phase 6 §12).
 *
 * ## No-show is not "cancelled by the customer"
 *
 * A customer who calls to say they cannot come has CANCELLED. A no-show is a
 * customer who did not appear, which is a fact that can only be known after the
 * appointment has started — so marking a future appointment as a no-show is
 * refused. Without that guard, "no-show" becomes a second, worse cancel button,
 * and the no-show history a later risk score depends on would be noise (§14).
 */
final class TransitionAppointment
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly BookingSettings $settings,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{reason?: string|null}  $options
     *
     * @throws BookingFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        Appointment $appointment,
        AppointmentStatus $target,
        BookingActor $actor,
        array $options = [],
        ?CarbonImmutable $now = null,
    ): Appointment {
        $this->entitlements->ensure('booking');

        $now ??= CarbonImmutable::now();

        $this->authorize($appointment, $target, $actor, $now);

        $from = $appointment->status;

        if (! $from->canTransitionTo($target)) {
            /*
             * The refusal names both ends on purpose. "Cannot confirm a
             * cancelled appointment" is actionable at a reception desk;
             * "invalid transition" is not. Neither end is sensitive — the
             * caller has already been authorised to see this appointment.
             */
            throw BookingFailed::invalidTransition(
                "An appointment that is {$from->value} cannot become {$target->value}.",
                ['from' => $from->value, 'to' => $target->value],
            );
        }

        $before = AppointmentSnapshot::of($appointment);
        $reason = $this->reason($options);

        DB::connection('tenant')->transaction(function () use ($appointment, $target, $from, $actor, $reason, $now): void {
            $attributes = ['status' => $target];

            // One timestamp per terminal-ish state. Queried directly by "how
            // many no-shows this month" — a question the audit log can answer
            // but should not have to.
            $attributes += match ($target) {
                AppointmentStatus::Confirmed => ['confirmed_at' => $now],
                AppointmentStatus::Completed => ['completed_at' => $now],
                AppointmentStatus::NoShow => ['no_show_at' => $now],
                AppointmentStatus::Cancelled => [
                    'cancelled_at' => $now,
                    // Where it was cancelled FROM. "Cancelled a confirmed
                    // booking" and "cancelled a provisional one" are different
                    // events to a center, and `status` can only hold the
                    // destination (§13).
                    'cancelled_from_status' => $from->value,
                    'cancellation_reason' => $reason,
                    'cancelled_by_type' => $actor->type->value,
                    'cancelled_by_id' => $actor->id,
                    'cancelled_by_label' => $actor->label,
                ],
                AppointmentStatus::Booked => [],
            };

            $appointment->forceFill($attributes)->save();
        });

        $this->audit->record(new AuditEvent(
            action: 'booking.appointment.'.$this->verb($target),
            category: AuditCategory::Config,
            actor: $actor->toAuditActor(),
            targetType: Appointment::class,
            targetId: $appointment->uuid,
            targetLabel: $appointment->customer()->value('name'),
            before: $before,
            after: AppointmentSnapshot::of($appointment->refresh()),
            // A free-text cancellation reason is written by a person and could
            // say anything, so it is recorded as the caller supplied it and
            // nothing is inferred from it (§32).
            reason: $reason,
        ));

        return $appointment;
    }

    /**
     * @throws AuthorizationException
     * @throws BookingFailed
     */
    private function authorize(
        Appointment $appointment,
        AppointmentStatus $target,
        BookingActor $actor,
        CarbonImmutable $now,
    ): void {
        if ($actor->isStaff()) {
            $this->authorizeStaff($appointment, $target, $actor, $now);

            return;
        }

        if (! $actor->isCustomer()) {
            // A guest cannot prove who they are on a later request. Changing a
            // guest booking is a phone call, by design.
            throw new AuthorizationException('You may not change appointments.');
        }

        if ($actor->account?->customer_id !== $appointment->customer_id) {
            throw new AuthorizationException('That appointment is not yours.');
        }

        // The ONLY transition a customer may make. Confirming on the customer's
        // behalf would make "confirmed" meaningless, and completing or marking
        // a no-show are the center's judgements about its own operation.
        if ($target !== AppointmentStatus::Cancelled) {
            throw new AuthorizationException('You may not change appointments.');
        }

        $notice = $this->settings->customerCancelNoticeMinutes();

        if ($appointment->starts_at->utc()->subMinutes($notice) <= $now->utc()) {
            throw BookingFailed::policy(
                $notice > 0
                    ? 'It is too late to cancel this booking online. Please contact the center.'
                    : 'This booking has already started. Please contact the center.',
                ['notice_minutes' => $notice],
            );
        }
    }

    /**
     * @throws AuthorizationException
     * @throws BookingFailed
     */
    private function authorizeStaff(
        Appointment $appointment,
        AppointmentStatus $target,
        BookingActor $actor,
        CarbonImmutable $now,
    ): void {
        $user = $actor->user;

        $permission = match ($target) {
            AppointmentStatus::Confirmed => Permission::AppointmentConfirm,
            AppointmentStatus::Completed => Permission::AppointmentComplete,
            AppointmentStatus::NoShow => Permission::AppointmentNoShow,
            AppointmentStatus::Cancelled => Permission::AppointmentCancel,
            AppointmentStatus::Booked => Permission::AppointmentUpdate,
        };

        if ($user === null || ! $user->hasPermission($permission)) {
            throw new AuthorizationException('You may not perform that action on appointments.');
        }

        if (! $user->canAccessBranch((int) $appointment->branch_id)) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        if ($target === AppointmentStatus::NoShow && ! $appointment->hasStarted($now)) {
            throw BookingFailed::policy(
                'An appointment cannot be marked as a no-show before it starts. Cancel it instead.'
            );
        }
    }

    /**
     * @param  array{reason?: string|null}  $options
     */
    private function reason(array $options): ?string
    {
        $reason = $options['reason'] ?? null;

        if (! is_string($reason)) {
            return null;
        }

        $reason = trim($reason);

        return $reason === '' ? null : mb_substr($reason, 0, 190);
    }

    private function verb(AppointmentStatus $target): string
    {
        return match ($target) {
            AppointmentStatus::Confirmed => 'confirmed',
            AppointmentStatus::Completed => 'completed',
            AppointmentStatus::Cancelled => 'cancelled',
            AppointmentStatus::NoShow => 'no_show',
            AppointmentStatus::Booked => 'reopened',
        };
    }
}
